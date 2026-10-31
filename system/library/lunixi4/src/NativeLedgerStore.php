<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\LedgerStore;
/** Same OpenCart DB object/connection as native order, stock and history writes. */
final class NativeLedgerStore implements LedgerStore
{
    private \Opencart\System\Library\DB $db;
    private string $prefix;
    private bool $active = false;
    private string $activeKey = '';
    private int $profileId;
    public function __construct(\Opencart\System\Library\DB $db, string $prefix, int $profileId = 0)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,35}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid store prefix.');
        }
        $this->db = $db;
        $this->prefix = $prefix;
        $this->profileId = $profileId;
    }
    public function isActive(): bool
    {
        return $this->active;
    }
    public function prefix(): string
    {
        return $this->prefix;
    }
    /** Plugin-owned tables only. Native engines are NEVER silently converted. */
    public function install(): void
    {
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_meta` (meta_key VARBINARY(100) PRIMARY KEY,value TEXT NOT NULL) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_profile` (profile_id INT AUTO_INCREMENT PRIMARY KEY,store_id INT NOT NULL,merchant_id VARBINARY(200) NOT NULL,ciphertext LONGTEXT NOT NULL,created_at BIGINT NOT NULL,KEY merchant(store_id,merchant_id)) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_attempt` (order_key VARBINARY(191) PRIMARY KEY,payload LONGTEXT NULL,profile_id INT NOT NULL DEFAULT 0,payment_id VARBINARY(200) NOT NULL DEFAULT \'\',native_order_id INT NOT NULL DEFAULT 0,store_id INT NOT NULL DEFAULT 0,state VARCHAR(32) NOT NULL DEFAULT \'reserved\',next_poll BIGINT NOT NULL DEFAULT 0,KEY payment_lookup(payment_id),KEY reconcile(state,next_poll)) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_event` (event_hash CHAR(64) PRIMARY KEY,payload_hash CHAR(64) NOT NULL,received_at BIGINT NOT NULL) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_application` (order_key VARBINARY(191) PRIMARY KEY,decision_hash CHAR(64) NOT NULL,payment_id VARBINARY(200) NOT NULL,captured_minor BIGINT NOT NULL,refunded_minor BIGINT NOT NULL,charged_minor BIGINT NOT NULL,state VARCHAR(32) NOT NULL,native_status_id INT NOT NULL) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_outbox` (outbox_id BIGINT AUTO_INCREMENT PRIMARY KEY,order_key VARBINARY(191) NOT NULL,native_order_id INT NOT NULL,old_status INT NOT NULL,new_status INT NOT NULL,comment_code VARCHAR(64) NOT NULL,state VARCHAR(16) NOT NULL DEFAULT \'pending\',created_at BIGINT NOT NULL,KEY delivery(state,outbox_id)) ENGINE=InnoDB');
        $this->query('CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'lunixi_operation` (operation_id VARBINARY(100) PRIMARY KEY,order_key VARBINARY(191) NOT NULL,action VARCHAR(16) NOT NULL,amount_minor BIGINT NULL,state VARCHAR(16) NOT NULL DEFAULT \'pending\',created_at BIGINT NOT NULL,KEY unresolved(order_key,state)) ENGINE=InnoDB');
    }
    /** Includes core/custom business tables because native total hooks may write to them. */
    public function meta(string $key): ?string
    {
        $r = $this->query('SELECT value FROM `' . $this->prefix . 'lunixi_meta` WHERE meta_key=' . $this->quote($key))->row;
        return isset($r['value']) ? (string) $r['value'] : null;
    }
    public function putMeta(string $key, string $value): void
    {
        $this->query('INSERT INTO `' . $this->prefix . 'lunixi_meta` (meta_key,value) VALUES (' . $this->quote($key) . ',' . $this->quote($value) . ') ON DUPLICATE KEY UPDATE value=VALUES(value)');
    }
    public function profileId(string $orderKey): int
    {
        $row = $this->query('SELECT profile_id FROM `' . $this->prefix . 'lunixi_attempt` WHERE order_key=' . $this->quote($orderKey))->row;
        return (int) ($row['profile_id'] ?? 0);
    }
    public function nonTransactionalTables(): array
    {
        $q = $this->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND LEFT(TABLE_NAME," . strlen($this->prefix) . ")=" . $this->quote($this->prefix) . " AND UPPER(ENGINE)<>'INNODB'");
        return array_map(static fn(array $row): string => (string) $row['TABLE_NAME'], $q->rows);
    }
    public function assertTransactional(): void
    {
        if ($this->nonTransactionalTables() !== []) {
            throw new \RuntimeException('Transactional native tables are required; run the documented operator-reviewed migration first.');
        }
    }
    public function transaction(string $orderKey, callable $operation)
    {
        if ($this->active || $orderKey === '' || strlen($orderKey) > 191) {
            throw new \RuntimeException('Invalid or nested ledger transaction.');
        }
        $this->assertTransactional();
        $this->active = true;
        $this->activeKey = $orderKey;
        try {
            $this->query('START TRANSACTION');
            $this->query('INSERT INTO `' . $this->prefix . 'lunixi_attempt` (order_key,payload) VALUES (' . $this->quote($orderKey) . ',NULL) ON DUPLICATE KEY UPDATE order_key=VALUES(order_key)');
            $this->query('SELECT order_key FROM `' . $this->prefix . 'lunixi_attempt` WHERE order_key=' . $this->quote($orderKey) . ' FOR UPDATE');
            $result = $operation();
            $this->query('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->query('ROLLBACK');
            } catch (\Throwable $ignored) {
            }
            throw new \RuntimeException('Lunixi transaction could not be completed.');
        } finally {
            $this->active = false;
            $this->activeKey = '';
        }
    }
    public function load(string $orderKey): ?array
    {
        $this->assertLock($orderKey);
        $q = $this->query('SELECT payload FROM `' . $this->prefix . 'lunixi_attempt` WHERE order_key=' . $this->quote($orderKey));
        return isset($q->row['payload']) ? json_decode($q->row['payload'], true, 64, JSON_THROW_ON_ERROR) : null;
    }
    public function save(string $orderKey, array $record): void
    {
        $this->assertLock($orderKey);
        $id = $this->nativeId($orderKey);
        $store = (int) explode(':', $orderKey)[1];
        $this->query('UPDATE `' . $this->prefix . 'lunixi_attempt` SET payload=' . $this->quote(json_encode($record, JSON_THROW_ON_ERROR)) . ',profile_id=IF(profile_id=0,' . $this->profileId . ',profile_id),payment_id=' . $this->quote((string) ($record['paymentId'] ?? '')) . ',native_order_id=' . $id . ',store_id=' . $store . ',state=' . $this->quote((string) ($record['state'] ?? 'reserved')) . ' WHERE order_key=' . $this->quote($orderKey));
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        $this->assertLock($this->activeKey);
        if ($merchantId === '' || $eventId === '' || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new \RuntimeException('Verified event identity and content are required.');
        }
        $hash = hash('sha256', $merchantId . "\x00" . $eventId);
        $this->query('INSERT IGNORE INTO `' . $this->prefix . 'lunixi_event` (event_hash,payload_hash,received_at) VALUES (' . $this->quote($hash) . ',' . $this->quote($payloadHash) . ',' . time() . ')');
        if ($this->db->countAffected() === 1) {
            return true;
        }
        $existing = $this->query('SELECT payload_hash FROM `' . $this->prefix . 'lunixi_event` WHERE event_hash=' . $this->quote($hash))->row['payload_hash'] ?? null;
        if (!is_string($existing) || !hash_equals($existing, $payloadHash)) {
            throw new \RuntimeException('Verified event content collision.');
        }
        return false;
    }
    public function findPayment(string $id, int $storeId): ?string
    {
        $q = $this->query('SELECT order_key FROM `' . $this->prefix . 'lunixi_attempt` WHERE payment_id=' . $this->quote($id) . ' AND store_id=' . $storeId . ' LIMIT 2');
        return $q->num_rows === 1 ? (string) $q->row['order_key'] : null;
    }
    public function reservePoll(string $orderKey, int $seconds = 15): bool
    {
        $this->query('UPDATE `' . $this->prefix . 'lunixi_attempt` SET next_poll=' . (time() + $seconds) . ' WHERE order_key=' . $this->quote($orderKey) . ' AND next_poll<=' . time());
        return $this->db->countAffected() === 1;
    }
    public function pendingKeys(int $storeId, int $limit = 50): array
    {
        $q = $this->query('SELECT order_key FROM `' . $this->prefix . 'lunixi_attempt` WHERE store_id=' . $storeId . " AND payload IS NOT NULL AND state NOT IN ('failed','refunded') AND next_poll<=" . time() . ' ORDER BY next_poll LIMIT ' . max(1, min(100, $limit)));
        return array_column($q->rows, 'order_key');
    }
    public function nativeId(string $orderKey): int
    {
        if (!preg_match('/^oc4:([0-9]+):([1-9][0-9]*)$/D', $orderKey, $m)) {
            throw new \RuntimeException('Invalid order binding.');
        }
        return (int) $m[2];
    }
    private function assertLock(string $key): void
    {
        if (!$this->active || $key !== $this->activeKey) {
            throw new \RuntimeException('Ledger access requires its active order lock.');
        }
    }
    public function quote(string $value): string
    {
        return "'" . $this->db->escape($value) . "'";
    }
    /** Native database exceptions may include SQL/data; never propagate them. */
    public function query(string $sql)
    {
        try {
            return $this->db->query($sql);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Lunixi storage is unavailable.');
        }
    }
}
