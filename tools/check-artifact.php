<?php
declare(strict_types=1);
$root=dirname(__DIR__);$moduleVersion=trim(file_get_contents($root.'/VERSION'));
$dependency=json_decode(file_get_contents($root.'/build/input/composer.json'),true,512,JSON_THROW_ON_ERROR);
$sdkVersion=$dependency['require']['lunixi/php-sdk']??null;
if(!is_string($sdkVersion)||!preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D',$sdkVersion)){throw new RuntimeException('SDK dependency must be an exact release version.');}
$lock=json_decode(file_get_contents($root.'/build/input/composer.lock'),true,512,JSON_THROW_ON_ERROR);
$resolved=array_values(array_filter($lock['packages'],static fn(array $package): bool=>$package['name']==='lunixi/php-sdk'));
if(count($resolved)!==1||!in_array($resolved[0]['version'],[$sdkVersion,'v'.$sdkVersion],true)){throw new RuntimeException('Resolved SDK does not match the exact release dependency.');}
$zip=new ZipArchive();if($zip->open($root.'/dist/lunixi.ocmod.zip')!==true){throw new RuntimeException('Build ZIP first.');}
$required=['system/library/lunixi4/sdk/Commerce/CheckoutService.php','system/library/lunixi4/sdk/autoload.php','system/library/lunixi4/autoload.php','admin/controller/payment/lunixi.php','catalog/controller/payment/lunixi.php','README.md','LICENSE','SECURITY.md','release.json'];
foreach($required as$file){if($zip->locateName($file)===false){throw new RuntimeException('Required merchant artifact file missing: '.$file);}}
for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);$value=$zip->getFromIndex($i);if(str_contains($name,'..')||str_starts_with($name,'/')||preg_match('~(?:^|/)(?:tests|tools|vendor|[.]git)/~',$name)){throw new RuntimeException('Unsafe merchant artifact layout.');}if(preg_match('~/(?:Users|home)/|git@[A-Za-z0-9.-]+|-----BEGIN PRIVATE KEY-----|synthetic_webhook_secret_~',$value)){throw new RuntimeException('Nonpublic data in merchant artifact.');}}
$release=json_decode($zip->getFromName('release.json'),true,16,JSON_THROW_ON_ERROR);if(($release['version']??null)!==$moduleVersion||($release['sdk']??null)!==$sdkVersion||($release['merchant_composer_required']??true)!==false){throw new RuntimeException('Incorrect SDK distribution metadata.');}
$installation=json_decode($zip->getFromName('install.json'),true,16,JSON_THROW_ON_ERROR);if(($installation['version']??null)!==$moduleVersion){throw new RuntimeException('OpenCart installer version must match VERSION.');}
$zip->close();echo "Standalone ZIP layout, scoped SDK, release metadata and secret/path checks passed.\n";
