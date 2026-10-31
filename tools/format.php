<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
$root=dirname(__DIR__);$parser=(new PhpParser\ParserFactory())->createForNewestSupportedVersion();$printer=new PhpParser\PrettyPrinter\Standard();$check=in_array('--check',$argv,true);$failed=false;
foreach(['admin','catalog','system','tests'] as$directory){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS))as$file){if($file->getExtension()!=='php'){continue;}$path=$file->getPathname();$source=file_get_contents($path);$formatted=$printer->prettyPrintFile($parser->parse($source))."\n";if($source!==$formatted){if($check){fwrite(STDERR,substr($path,strlen($root)+1)." requires formatting\n");$failed=true;}else{file_put_contents($path,$formatted);}}}}
exit($failed?1:0);
