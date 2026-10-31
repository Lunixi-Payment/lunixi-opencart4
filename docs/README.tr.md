<p align="center"><img src="../.github/assets/lunixi-logo.svg" alt="Lunixi" width="220"></p>

# OpenCart 4 için Lunixi Payments

**0.1.3**, özgün **OpenCart 4.1.0.4** sipariş modeli için hazırlanmış yerel ödeme eklentisidir. 64 bit PHP **8.3+**, BCMath, cURL, JSON, Sodium, MySQLi ve tüm mağaza tablolarında **InnoDB** gerekir. Paket, ad alanı ayrılmış PHP SDK **0.29.6** içerir; mağazada Composer gerekmez.

## Kurulum

1. Dosya/veritabanı yedeği alın ve önce staging ortamında deneyin. Üçüncü taraf tablolar dahil InnoDB ön kontrolünü tamamlayın.
2. `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` ile ayrı sunucu anahtarı oluşturun. `LUNIXI_OPENCART_ENCRYPTION_KEY` ortam değişkenini web PHP ve CLI için tanımlayın; web kökü/veritabanı dışında saklayıp ayrıca yedekleyin.
3. **`lunixi.ocmod.zip`** dosyasını **Eklentiler → Yükleyici** üzerinden yükleyin, ardından **Ödemeler → Lunixi Payments** kurulumunu yapın. ZIP adını değiştirmeyin; OpenCart 4 extension/lunixi klasörünü bu addan oluşturur.
4. Yöneticiye eklentiyi görme/değiştirme; tahsilat, iade ve iptal için ayrıca `sale/order` değiştirme yetkisi verin.
5. Lunixi ayarlarında mağazayı seçin. Merchant ID, Key ID, Ed25519 özel anahtarı ve webhook secret alanlarını doldurun. Anahtarlar şifreli saklanır; sayfa tekrar açıldığında boş kalır. Boş alanlar kayıtlı profili korur. Her mağazaya ayrı profil kullanın.
6. Varsayılan **mağaza içi formu** kullanın veya backend ödeme adresine isteğe bağlı yönlendirmeyi seçin; ön provizyon ayrıca ayarlanabilir. Ödeme yöntemleri, uygun yemek kartları, alışveriş kredisi, taksit, sigorta ve çoklu kart Lunixi panelindeki form ayarlarından gelir; burada tekrar seçilmez.
7. Modül ayrı bekleyen/ön provizyon/inceleme durumları oluşturur. Yalnız ödenmiş durumunu OpenCart işleme/tamamlandı kümesine eşleyin; diğer durumlar bu kümelerin dışında kalmalıdır. Ön provizyon ve kısmi tahsilat ürün teslimini başlatmaz.
8. Ekrandaki HTTPS webhook adresini Lunixi paneline kaydedin: `https://magaza.example/index.php?route=extension/lunixi/payment/lunixi.webhook`. Proxy ham gövdeyi ve `X-Lunixi-*` başlıklarını korumalıdır.
9. Aşağıdaki görevi her dakika ve her mağaza için çalıştırıp sonra modülü etkinleştirin.

```sh
php /absolute/shop/extension/lunixi/system/library/lunixi4/cli.php --root=/absolute/shop --store-id=0
```

## Tahsilat, iade ve güvenlik

Yönetim ekranı tahsilat/iade/iptal işlemini kalıcı işlem kimliğiyle kuyruğa alır. Katalog bağlamındaki görev gerçek OpenCart sipariş/stok kodunu yürütür. **Bekleyen veya belirsiz işlem başarılı değildir.** Aynı kimlik ve tutarla takip/tekrar yapılır; sonuç çözülmeden farklı işlem açılmaz. Başarı ancak kimlik doğrulamalı API bakiye sorgusu istenen hareketi kanıtladığında gösterilir. İade gerçekten tahsil edilen bakiyeyi aşamaz. Çoklu kart iadesi ortak SDK'nın üst ödeme iade akışını kullanır.

Tarayıcı dönüşü, query parametresi veya form olayı siparişi ödendi yapmaz. Oturum/müşteri/mağaza/sipariş sahipliği kontrol edilir. Ham gövde v2 HMAC, zaman ve olay kimliği doğrulanır; ardından kimlik doğrulamalı ödeme sorgusu merchant, ödeme, sabit sipariş izi, para birimi, tam sayı tutar, vade farkı ve çoklu kart üst ödeme sonucunu doğrular. Sipariş tutarı sonradan değiştiyse eski ödeme siparişi tamamlamaz. Vade farkı sepete tekrar eklenmez. Eksik çoklu kart veya kısmi tahsilat bekler; yerel fraud/manual teslim blokesi korunur.

Olay kutusu ve sipariş değişikliği aynı bağlantı/transaction içinde tutulur; hata halinde stok ve olay tüketimi birlikte geri alınır. Yinelenen veya eşzamanlı webhook ikinci stok düşümüne neden olmaz. Yerel iş/fraud event'leri korunur; yalnız denetlenmiş e-posta bildirimleri commit sonrasına ertelenir. E-postanın `sending`/`uncertain` durumu operatör incelemesi gerektirir, otomatik tekrar gönderilmez.

Farklı platform sürümü, değiştirilmiş yerel sipariş modeli, MyISAM veya denetlenmemiş üçüncü taraf sipariş/total/fraud hook'ları ön kontrolde reddedilir. Tüm tema/eklenti kombinasyonları destekleniyor iddiası yoktur. Yerel tekrarlayan abonelik sepetleri kapalıdır. Özel CSP için güvenilir sunucu entegrasyonu yanıtın `lunixi_csp_nonce` config değerini belirleyebilir. Resmî form paketi yalnız `https://cdn.lunixi.com/lunixi-checkout.min.js` üzerinden yüklenir.

Varsayılan gateway `https://api-gateway.lunixi.com` adresidir. Farklı origin yalnız operatörün HTTPS allowlist ortam ayarıyla seçilebilir. Eski ödeme ve iade kayıtları için eski şifreli credential profilleri ile ayrı sunucu anahtarını saklayın; Lunixi tarafında anahtar iptalini bu saklama süresine göre planlayın. Kaldırma işlemi finansal geçmişi, profilleri, işlem kimliklerini, webhook/e-posta kuyruklarını ve özel durumları silmez.

## Geliştirme ve doğrulama

```sh
composer install --working-dir=tools --no-interaction
php tools/format.php --check
php tools/build.php
php tools/check-artifact.php
composer audit --working-dir=tools
```

Yayımlanmamış ortak SDK'yı test etmek için yalnız derleme sırasında `SDK_SOURCE=/absolute/public-sdk-checkout` verilebilir. Bu yol ZIP'e yazılmaz. [Gerçek platform testleri](TESTING.md), [operasyon ve veritabanı hazırlığı](OPERATIONS.md), [güvenlik politikası](../SECURITY.md) ve [English README](../README.md) ayrıntıları içerir. Gerçek platform kabulü PHP 8.4.1/MariaDB 11 üzerinde, dış gateway yerine sentetik transport ile yapılır. Marketplace onayı veya canlı banka tahsilatı iddiası yoktur.

Eklenti GPL-3.0-or-later, paketlenen PHP SDK MIT lisanslıdır.

Finansal sonucu belirsiz işlemler incelemede tutulur. `FAILED` tek başına yeni denemeye izin vermez; herkese açık ödeme sorgusu kesin sağlayıcı sonuç kanıtını döndürmez. Sonuçlanmamış finansal tekrarlar 23 saat sonra durur; kimliği doğrulanmış GET mutabakatı devam eder. Zaman aşımı, form token süresi veya tarayıcı dönüşü yeni finansal anahtar üretmez.

## Sağlayıcı tarayıcı dönüşü

Lunixi yönlendirmeli, çoklu kart ve 3DS akışları üretilen `returned` adresine **POST** ile döner. Yalnız bu bilgilendirici dönüş ucu oturum/CSRF token olmadan POST kabul eder: form gövdesinin tamamını yok sayıp özgün dönüş URL'sindeki sayısal `order_id` ile aynı sitedeki göreli GET adresine **303** döner. Ödeme sorgulamaz, sipariş verisi göstermez ve finansal kayıt değiştirmez. GET aşamasında yerel oturum/müşteri/mağaza/sipariş sahipliği ve kimlik doğrulamalı ödeme sorgusu korunur. Checkout/yönetici POST korumaları devam eder; tarayıcının başarı alanları ödeme kanıtı değildir.

Uygulama dönüşün bildirilen gövdesi **64 KiB** üzerindeyse reddeder. HTTPS proxy'de bu uç için chunked istekleri de kapsayan aynı ağ gövdesi sınırını uygulayın; imzalı webhook'un ayrı 1 MiB sınırını koruyun. Dönüşü checkout/confirm POST adresine çevirmeyin, GET sahiplik kontrolünü kaldırmayın.

## Form modu ve mevcut ödeme denemeleri

Varsayılan, mağaza içinde açılan gömülü Lunixi formudur. İsteğe bağlı yönlendirme, backend'in döndürdüğü doğrulanmış `paymentPageUrl` adresini birebir kullanır. İki mod da aynı formu açar; açık yöntemler, çoklu kart, taksit ve sigorta Lunixi panelinden yönetilir ve yönlendirme gerektirmez. Tarayıcı token'ından ödeme sayfası adresi üretilmez.

Önceden açıkça seçilmiş yönlendirme ayarları korunur. Form modu, dönüş adresi, ön provizyon ve dil özgün ödeme isteğine bağlıdır; değiştirmek denemenin hash veya idempotency anahtarını sıfırlayamaz. Eski bir denemede backend ödeme adresi kayıtlı değilse yönlendirme güvenli biçimde durur; adres üretmek, geçmişi silmek veya yeni anahtar açmak yerine mevcut denemeyi uzlaştırın. Gömülü form korumalı tarayıcı yapılandırmasını kullanır. İmzalı webhook, doğrulanmış tahsilat kontrolleri ve bilgilendirici 3DS/çoklu kart dönüşleri korunur.

0.1.3 paketi, yayımlanmış PHP SDK **0.29.6** ile yeniden derlenip ayrı izole MariaDB üzerinde doğrulandı: **11 yerel platform testi / 81 assertion** ve **21 ek HTTP kontrolü** geçti. Harici Lunixi transport'u sentetiktir; tam sepet arayüzü, canlı banka/CDN/3DS veya Marketplace onayı iddia edilmez. Yerel PHP sürümü 8.4.1'dir; SDK üst akıştaki PHP 8.5 cURL düzeltmesini içerir. Ayrıntılar [test kaydındadır](TESTING.md#013-sdk-refresh-acceptance).
