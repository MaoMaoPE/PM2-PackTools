<?php
/**
*@author:lovol(qq:1669439552/3445778610)
*有问题可以联系我
*\(  ͯω  ͯ)/
*/



function colorText($text, $colorCode) {
    return "\033[" . $colorCode . "m" . $text . "\033[0m";
}

function successMessage($message) {
    echo colorText($message . "\n", "32"); 
}

function errorMessage($message) {
    echo colorText("错误: " . $message . "\n", "31"); 
    exit(1);
}

function checkExistence($path, $isFile = true) {
    if ($isFile && !file_exists($path)) {
        errorMessage("文件不存在: $path");
    } elseif (!$isFile && !is_dir($path)) {
        errorMessage("目录不存在: $path");
    }
}
function displayMenu() {

    echo colorText("=============================\n", "36"); 
    echo colorText(" 请选择操作：\n", "36");
    echo " 1. 解包 \n";
    echo " 2. 打包 \n";
    echo " 3. 获取 Phar stub \n";
    echo " 4. 添加 Phar stub \n";
    echo colorText("=============================\n", "36");
}

function prompt($message) {
    echo colorText($message, "33"); 
    return trim(fgets(STDIN));
}

function unpackPhar() {
    $input = prompt("输入要解包的 phar 文件的名字（不带 .phar 后缀）：");
    $input2 = prompt("输入解压的目标文件夹名字：");
    $pharFile = "$input.phar";

    try {
        checkExistence($pharFile);

        if (!mkdir($input2, 0700) && !is_dir($input2)) {
            throw new Exception("无法创建目标文件夹: $input2");
        }

       
                     $phar = new Phar($pharFile);
        $phar->extractTo($input2);
        successMessage("解包完成！文件已解压至：$input2");
    } catch (Exception $e) {
        errorMessage($e->getMessage());
    }
}

function packPhar() {
    $inputFolder = prompt("输入要打包的文件夹名字：");
    $outputPhar = prompt("输入打包后的 phar 文件名（不带 .phar 后缀）：");
    $pharFile = "$outputPhar.phar";

    try {
        checkExistence($inputFolder, false);

        $phar = new Phar($pharFile);
        $phar->buildFromDirectory($inputFolder);
        $phar->setStub($phar->createDefaultStub('
<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\server_phar_stub;

use function clearstatcache;
use function copy;
use function define;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;
use function getmypid;
use function hrtime;
use function is_dir;
use function is_file;
use function mkdir;
use function number_format;
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Finds the appropriate tmp directory to store the decompressed phar cache, accounting for potential file name
 * collisions.
 */
function preparePharCacheDirectory() : string{
        clearstatcache();

        $i = 0;
        do{
                $tmpPath = sys_get_temp_dir() . '/PocketMine-MP-phar-cache.' . $i;
                $i++;
        }while(is_file($tmpPath));
        if(!@mkdir($tmpPath) && !is_dir($tmpPath)){
                throw new \RuntimeException("Failed to create temporary directory $tmpPath. Please ensure the disk has enough space and that the current user has permission to write to this location.");
        }

        return $tmpPath;
}

/**
 * Deletes caches left behind by previous server instances.
 * This ensures that the tmp directory doesn't get flooded by servers crashing in restart loops.
 */
function cleanupPharCache(string $tmpPath) : void{
        clearstatcache();

        /** @var string[] $matches */
        foreach(new \RegexIterator(
                new \FilesystemIterator(
                        $tmpPath,
                        \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                ),
                '/(.+)\.lock$/',
                \RegexIterator::GET_MATCH
        ) as $matches){
                $lockFilePath = $matches[0];
                $baseTmpPath = $matches[1];

                $file = @fopen($lockFilePath, "rb");
                if($file === false){
                        //another process probably deleted the lock file already
                        continue;
                }

                if(flock($file, LOCK_EX | LOCK_NB)){
                        //this tmpfile is no longer in use
                        flock($file, LOCK_UN);
                        fclose($file);

                        unlink($lockFilePath);
                        unlink($baseTmpPath . ".tar");
                        unlink($baseTmpPath);
                        echo "Deleted stale phar cache at $baseTmpPath\n";
                }else{
                        $pid = stream_get_contents($file);
                        fclose($file);

                        echo "Phar cache at $baseTmpPath is still in use by PID $pid\n";
                }
        }
}

function convertPharToTar(string $tmpName, string $pharPath) : string{
        $tmpPharPath = $tmpName . ".phar";
        copy($pharPath, $tmpPharPath);

        $phar = new \Phar($tmpPharPath);
        //phar requires phar.readonly=0, and zip doesn't support disabling compression - tar is the only viable option
        //we don't need phar anyway since we don't need to directly execute the file, only require files from inside it
        $phar->convertToData(\Phar::TAR, \Phar::NONE);
        unset($phar);
        \Phar::unlinkArchive($tmpPharPath);

        return $tmpName . ".tar";
}

/**
 * Locks a phar tmp cache to prevent it from being deleted by other server instances.
 * This code looks similar to Filesystem::createLockFile(), but we can't use that because it's inside the compressed
 * phar.
 */
function lockPharCache(string $lockFilePath) : void{
        //this static variable will keep the file(s) locked until the process ends
        static $lockFiles = [];

        $lockFile = fopen($lockFilePath, "wb");
        if($lockFile === false){
                throw new \RuntimeException("Failed to open temporary file");
        }
        flock($lockFile, LOCK_EX); //this tells other server instances not to delete this cache file
        fwrite($lockFile, (string) getmypid()); //maybe useful for debugging
        fflush($lockFile);
        $lockFiles[$lockFilePath] = $lockFile;
}

/**
 * Prepares a decompressed .tar of PocketMine-MP.phar in the system temp directory for loading code from.
 *
 * @return string path to the temporary decompressed phar (actually a .tar)
 */
function preparePharCache(string $tmpPath, string $pharPath) : string{
        clearstatcache();

        $tmpName = tempnam($tmpPath, "PMMP");
        if($tmpName === false){
                throw new \RuntimeException("Failed to create temporary file");
        }

        lockPharCache($tmpName . ".lock");
        return convertPharToTar($tmpName, $pharPath);
}

$tmpDir = preparePharCacheDirectory();
cleanupPharCache($tmpDir);
echo "Preparing PocketMine-MP.phar decompressed cache...\n";
$start = hrtime(true);
$cacheName = preparePharCache($tmpDir, __FILE__);
echo "Cache ready at $cacheName in " . number_format((hrtime(true) - $start) / 1e9, 2) . "s\n";

define('pocketmine\ORIGINAL_PHAR_PATH', __FILE__);
require 'phar://' . str_replace(DIRECTORY_SEPARATOR, '/', $cacheName) . '/src/PocketMine.php';

__HALT_COMPILER();')); 
        successMessage("打包完成！生成的 Phar 文件：$pharFile");
    } catch (Exception $e) {
        errorMessage($e->getMessage());
    }
}

function getPharStub() {
    $pharName = prompt("输入要获取 stub 的 phar 文件名（不带 .phar 后缀）：");
    $pharFile = "$pharName.phar";

    try {
        checkExistence($pharFile);
        
        $phar = new Phar($pharFile);
        $stub = $phar->getStub();

        echo colorText("当前 Phar 的 stub 内容:\n", "36"); // 青色
        echo colorText("-------------------------\n", "36");
        echo $stub . "\n";
        echo colorText("-------------------------\n", "36");
    } catch (Exception $e) {
        errorMessage($e->getMessage());
    }
}

function setPharStub() {
    $pharName = prompt("输入要添加 stub 的 phar 文件名（不带 .phar 后缀）：");
    $pharFile = "$pharName.phar";
    $newStub = prompt("输入新的 stub 内容（以一行输入）：");

    try {
        checkExistence($pharFile); 
        
        
        $phar = new Phar($pharFile);
        $phar->setStub($newStub);
        successMessage("Stub 已成功更新！");
    } catch (Exception $e) {
        errorMessage($e->getMessage());
    }
}


while (true) {
    displayMenu();
    $operation = trim(fgets(STDIN));

    switch ($operation) {
        case '1':
            unpackPhar();
            break;
        case '2':
            packPhar();
            break;
        case '3':
            getPharStub();
            break;
        case '4':
            setPharStub();
            break;
        default:
            errorMessage("无效的操作。");
    }

    $continue = prompt("是否继续操作？(y/n)：");
    if (strtolower($continue) !== 'y') {
        successMessage("操作结束，感谢使用！");
        break;
    }
}
?>
