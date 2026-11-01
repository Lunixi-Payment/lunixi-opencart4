<?php
declare(strict_types=1);
/** Build a standalone merchant OCMOD ZIP; Composer is a build-time tool only. */
$root=dirname(__DIR__);
$local=getenv('SDK_SOURCE')?:null;
if ($local!==null && (!is_file($local . '/composer.json') || !is_file($local . '/src/Commerce/CheckoutService.php'))) { throw new RuntimeException('SDK_SOURCE must point to the supported public SDK source checkout.'); }
function ocRun(array $command,string $cwd): void { $p=proc_open($command,[STDIN,STDOUT,STDERR],$pipes,$cwd); if (!is_resource($p) || proc_close($p)!==0) { throw new RuntimeException('Release build command failed.'); } }
function ocRemove(string $path): void { if (!is_dir($path)) { return; } foreach(new DirectoryIterator($path) as $f) { if($f->isDot()){continue;} if($f->isDir()&&!$f->isLink()){ocRemove($f->getPathname());}else{unlink($f->getPathname());} } rmdir($path); }
function ocCopy(string $source,string $target): void { if(is_dir($source)){if(!is_dir($target)){mkdir($target,0755,true);}foreach(new DirectoryIterator($source)as$f){if(!$f->isDot()){ocCopy($f->getPathname(),$target.'/'.$f->getFilename());}}}else{if(!is_dir(dirname($target))){mkdir(dirname($target),0755,true);}copy($source,$target);} }
$input=$root.'/build/input';$output=$root.'/build/package';ocRemove($input);ocRemove($output);mkdir($input,0755,true);mkdir($output,0755,true);
$composer=['name'=>'lunixi/opencart4-release-input','require'=>['lunixi/php-sdk'=>'1.0.1'],'config'=>['allow-plugins'=>false,'sort-packages'=>true]];
if($local!==null){$composer['repositories']=[['type'=>'path','url'=>realpath($local),'options'=>['symlink'=>false]]];}
file_put_contents($input.'/composer.json',json_encode($composer,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
ocRun(['composer','install','--no-dev','--no-interaction','--prefer-dist','--classmap-authoritative'],$input);
foreach(['admin','catalog','system','install.json'] as $part){ocCopy($root.'/'.$part,$output.'/'.$part);}
$sdk=$input.'/vendor/lunixi/php-sdk';$runtime=$output.'/system/library/lunixi4';
ocRun([PHP_BINARY,$root.'/tools/vendor/bin/php-scoper','add-prefix','src','--output-dir='.$runtime.'/sdk','--config='.$root.'/scoper.inc.php','--force','--no-interaction'],$sdk);
ocCopy($sdk.'/LICENSE',$runtime.'/sdk/LICENSE');
$prefix='Lunixi\\OpenCart4\\Vendor\\Lunixi\\Sdk\\';
$loader="<?php\ndeclare(strict_types=1);\nspl_autoload_register(static function (string \$class): void {\n";
$loader.='    $prefix='.var_export($prefix,true).";\n";
$loader.='    if (strncmp($class,$prefix,strlen($prefix))===0) { $file=__DIR__."/".str_replace("\\\\","/",substr($class,strlen($prefix))).".php"; if(is_file($file)){require_once $file;} }' . "\n});\n";
file_put_contents($runtime.'/sdk/autoload.php',$loader);
if(!is_file($runtime.'/sdk/Commerce/CheckoutService.php')){throw new RuntimeException('Scoped SDK layout is invalid.');}
file_put_contents($runtime.'/.htaccess',"Require all denied\n");
ocCopy($root.'/docs',$output.'/docs');ocCopy($root.'/.github/assets',$output.'/.github/assets');
foreach(['README.md','LICENSE','CHANGELOG.md','MIGRATION-1.0.md','SECURITY.md']as$file){if(is_file($root.'/'.$file)){ocCopy($root.'/'.$file,$output.'/'.$file);}}
file_put_contents($output.'/release.json',json_encode(['module'=>'lunixi-opencart4','version'=>'1.0.0','opencart'=>'4.1.0.4','sdk'=>'1.0.0','php_min'=>'8.3','merchant_composer_required'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if(!is_dir($root.'/dist')){mkdir($root.'/dist');}
$target=$root.'/dist/lunixi.ocmod.zip';$zip=new ZipArchive();if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){throw new RuntimeException('Cannot create native ZIP.');}
$files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output,FilesystemIterator::SKIP_DOTS))as$file){if($file->isFile()){$files[]=$file->getPathname();}}sort($files,SORT_STRING);
foreach($files as$file){$bytes=file_get_contents($file);if(preg_match('~/(?:Users|home)/|git@[A-Za-z0-9.-]+|(?:\\.\\./){2}php/~',$bytes)){throw new RuntimeException('Internal path/reference in artifact.');}$name=substr($file,strlen($output)+1);$zip->addFile($file,$name);$zip->setMtimeName($name,1793404800);}
$zip->close();echo basename($target).' SHA256 '.hash_file('sha256',$target)."\n";
