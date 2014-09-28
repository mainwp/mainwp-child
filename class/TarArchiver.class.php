<?php

class TarArchiver
{
    protected $excludeZip;

    protected $archive;
    protected $lastRun = 0;

    /** @var $backup MainWPBackup */
    protected $backup;

    protected $type;

    public function __construct($backup, $type = 'tar')
    {
        $this->backup = $backup;

        $this->type = $type;
        if ($this->type == 'tar.bz2')
        {
            if (!function_exists('bzopen'))
            {
                $this->type = 'tar.gz';
            }
        }

        if ($this->type == 'tar.gz')
        {
            if (!function_exists('gzopen'))
            {
                $this->type = 'tar';
            }
        }
    }

    public function getExtension()
    {
        if ($this->type == 'tar.bz2') return '.tar.bz2';
        if ($this->type == 'tar.gz') return '.tar.gz';
        return '.tar';
    }

    public function createFullBackup($filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp)
    {
        $this->excludeZip = $excludezip;
        $this->create($filepath);

        if ($this->archive)
        {
            $nodes = glob(ABSPATH . '*');
            if (!$includeCoreFiles)
            {
                $coreFiles = array('favicon.ico', 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-app.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-pass.php', 'wp-register.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php');
                foreach ($nodes as $key => $node)
                {
                    if (MainWPHelper::startsWith($node, ABSPATH . WPINC))
                    {
                        unset($nodes[$key]);
                    }
                    else if (MainWPHelper::startsWith($node, ABSPATH . basename(admin_url(''))))
                    {
                        unset($nodes[$key]);
                    }
                    else
                    {
                        foreach ($coreFiles as $coreFile)
                        {
                            if ($node == ABSPATH . $coreFile) unset($nodes[$key]);
                        }
                    }
                }
                unset($coreFiles);
            }

            $this->backup->createBackupDB(dirname($filepath) . DIRECTORY_SEPARATOR . 'dbBackup.sql');
            $this->addFile(dirname($filepath) . DIRECTORY_SEPARATOR . 'dbBackup.sql', basename(WP_CONTENT_DIR) . '/' . 'dbBackup.sql');
            if (file_exists(ABSPATH . '.htaccess')) $this->addFile(ABSPATH . '.htaccess', 'mainwp-htaccess');

            foreach ($nodes as $node)
            {
                if ($excludenonwp && is_dir($node))
                {
                    if (!MainWPHelper::startsWith($node, WP_CONTENT_DIR) && !MainWPHelper::startsWith($node, ABSPATH . 'wp-admin') && !MainWPHelper::startsWith($node, ABSPATH . WPINC)) continue;
                }

                if (!MainWPHelper::inExcludes($excludes, str_replace(ABSPATH, '', $node)))
                {
                    if (is_dir($node))
                    {
                        $this->addDir($node, $excludes);
                    }
                    else if (is_file($node))
                    {
                        $this->addFile($node, str_replace(ABSPATH, '', $node));
                    }
                }
            }

            if ($addConfig)
            {
                global $wpdb;
                $plugins = array();
                $dir = WP_CONTENT_DIR . '/plugins/';
                $fh = @opendir($dir);
                while ($entry = @readdir($fh))
                {
                    if (!@is_dir($dir . $entry)) continue;
                    if (($entry == '.') || ($entry == '..')) continue;
                    $plugins[] = $entry;
                }
                @closedir($fh);

                $themes = array();
                $dir = WP_CONTENT_DIR . '/themes/';
                $fh = @opendir($dir);
                while ($entry = @readdir($fh))
                {
                    if (!@is_dir($dir . $entry)) continue;
                    if (($entry == '.') || ($entry == '..')) continue;
                    $themes[] = $entry;
                }
                @closedir($fh);

                $string = base64_encode(serialize(array('siteurl' => get_option('siteurl'),
                    'home' => get_option('home'),
                    'abspath' => ABSPATH,
                    'prefix' => $wpdb->prefix,
                    'lang' => WPLANG,
                    'plugins' => $plugins,
                    'themes' => $themes)));

                $this->addFileFromString('clone/config.txt', $string);
            }

            $this->addData(pack("a1024", ""));
            @fclose($this->archive);
            @unlink(dirname($filepath) . DIRECTORY_SEPARATOR . 'dbBackup.sql');

            return true;
        }

        return false;
    }

    private function addDir($path, $excludes)
    {
        $this->addEmptyDir($path, str_replace(ABSPATH, '', $path));

        if (file_exists(rtrim($path, '/') . '/.htaccess')) $this->addFile(rtrim($path, '/') . '/.htaccess', rtrim(str_replace(ABSPATH, '', $path), '/') . '/mainwp-htaccess');

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);

        /** @var $path DirectoryIterator */
        foreach ($iterator as $path)
        {
            $name = $path->__toString();
            if (MainWPHelper::endsWith($name, '/.') || MainWPHelper::endsWith($name, '/..')) continue;

            if (!MainWPHelper::inExcludes($excludes, str_replace(ABSPATH, '', $name)))
            {
                if ($path->isDir())
                {
                    $this->addEmptyDir($name, str_replace(ABSPATH, '', $name));
                }
                else
                {
                    $this->addFile($name, str_replace(ABSPATH, '', $name));
                }
            }
            $name = null;
            unset($name);
        }

        $iterator = null;
        unset($iterator);
    }

    private function addData($data)
    {
        @fwrite($this->archive, $data);
    }

    private function addEmptyDir($path, $entryName)
    {
        $prefix = "";
        if (strlen($entryName) > 99)
        {
            $prefix = substr($entryName, 0, strpos($entryName, "/", strlen($entryName) - 100) + 1);
            $entryName = substr($entryName, strlen($prefix));
            if (strlen($prefix) > 154 || strlen($entryName) > 99)
            {
                //todo: add some error feedback!
            }
        }
        $stat = @stat($path);

        $block = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
            $entryName,
            sprintf("%07o", $stat['mode']),
            sprintf("%07o", $stat['uid']),
            sprintf("%07o", $stat['gid']),
            sprintf("%011o", 0),
            sprintf("%011o", $stat['mtime']),
            "        ",
            5,
            "",
            "ustar",
            " ",
            "Unknown",
            "Unknown",
            "",
            "",
            $prefix,
            "");

        $checksum = 0;
        for ($i = 0; $i < 512; $i++)
            $checksum += ord(substr($block, $i, 1));
        $checksum = pack("a8", sprintf("%07o", $checksum));
        $block = substr_replace($block, $checksum, 148, 8);

        $this->addData($block);

        return true;
    }

    private function addFile($path, $entryName)
    {
        if (time() - $this->lastRun > 60)
        {
            @set_time_limit(20 * 60 * 60); /*20 minutes*/
            $this->lastRun = time();
        }

        if ($this->excludeZip && MainWPHelper::endsWith($path, '.zip')) return false;

        $prefix = "";
        if (strlen($entryName) > 99)
        {
            $prefix = substr($entryName, 0, strpos($entryName, "/", strlen($entryName) - 100) + 1);
            $entryName = substr($entryName, strlen($prefix));
            if (strlen($prefix) > 154 || strlen($entryName) > 99)
            {
                //todo: add some error feedback!
            }
        }
        $stat = @stat($path);
        $fp = @fopen($path, "rb");
        if (!$fp)
        {
            //todo: add some error feedback!
        }

        $block = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
            $entryName,
            sprintf("%07o", $stat['mode']),
            sprintf("%07o", $stat['uid']),
            sprintf("%07o", $stat['gid']),
            sprintf("%011o", $stat['size']),
            sprintf("%011o", $stat['mtime']),
            "        ",
            0,
            "",
            "ustar",
            " ",
            "Unknown",
            "Unknown",
            "",
            "",
            $prefix,
            "");

        $checksum = 0;
        for ($i = 0; $i < 512; $i++)
            $checksum += ord(substr($block, $i, 1));
        $checksum = pack("a8", sprintf("%07o", $checksum));
        $block = substr_replace($block, $checksum, 148, 8);

        $this->addData($block);

        while (!feof($fp))
        {
            $temp = fread($fp, 512);
            if ($temp)
            {
                $this->addData(pack("a512", $temp));
            }
        }
        @fclose($fp);

        return true;
    }

    private function addFileFromString($entryName, $content)
    {

        $prefix = "";
        if (strlen($entryName) > 99)
        {
            $prefix = substr($entryName, 0, strpos($entryName, "/", strlen($entryName) - 100) + 1);
            $entryName = substr($entryName, strlen($prefix));
            if (strlen($prefix) > 154 || strlen($entryName) > 99)
            {
                //todo: add some error feedback!
            }
        }

        $block = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
            $entryName,
            sprintf("%07o", 0),
            sprintf("%07o", 0),
            sprintf("%07o", 0),
            sprintf("%011o", strlen($content)),
            sprintf("%011o", time()),
            "        ",
            0,
            "",
            "ustar",
            " ",
            "Unknown",
            "Unknown",
            "",
            "",
            $prefix,
            "");

        $checksum = 0;
        for ($i = 0; $i < 512; $i++)
            $checksum += ord(substr($block, $i, 1));
        $checksum = pack("a8", sprintf("%07o", $checksum));
        $block = substr_replace($block, $checksum, 148, 8);

        $this->addData($block);
        $i = 0;
        while (($line = substr($content, $i++ * 512, 512)) != '')
        {
            $this->addData(pack("a512", $line));
        }
        return true;
    }

    function create($filepath)
    {
        if ($this->type == 'tar.gz')
        {
            $this->archive = @fopen('compress.zlib://' . $filepath, 'ab');
        }
        else if ($this->type == 'tar.bz2')
        {
            $this->archive = @bzopen($filepath, 'w');
        }
        else
        {
            $this->archive = @fopen($filepath, 'wb+');
        }
    }

    function isOpen()
    {
        return !empty($this->archive);
    }

    function read($filepath)
    {
        if (substr($filepath, -6) == 'tar.gz')
        {
            $this->type = 'tar.gz';
            $this->archive = @gzopen($filepath, 'rb');
        }
        else if (substr($filepath, -7) == 'tar.bz2')
        {
            $this->type = 'tar.bz2';
            $this->archive = @bzopen($filepath, 'r');
        }
        else
        {
            $this->type = 'tar';
            $this->archive = @fopen($filepath, 'rb');
        }
    }

    function close()
    {
        if ($this->archive)
        {
            @fclose($this->archive);
        }
    }

    function getFromName($entryName)
    {
        if (!$this->archive) return false;
        if (empty($entryName)) return false;
        $content = false;
        while ($block = @fread($this->archive, 512))
        {
            $temp = unpack("a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp", $block);
            $file = array(
                'name' => $temp['prefix'] . $temp['name'],
                'stat' => array(
                    2 => $temp['mode'],
                    4 => octdec($temp['uid']),
                    5 => octdec($temp['gid']),
                    7 => octdec($temp['size']),
                    9 => octdec($temp['mtime']),
                ),
                'checksum' => octdec($temp['checksum']),
                'type' => $temp['type'],
                'magic' => $temp['magic'],
            );

            if ($file['checksum'] == 0x00000000)
                break;

            else if (substr($file['magic'], 0, 5) != "ustar")
            {
//                $this->error[] = "This script does not support extracting this type of tar file.";
                break;
            }

            $block = substr_replace($block, "        ", 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++)
                $checksum += ord(substr($block, $i, 1));
//            if ($file['checksum'] != $checksum)
//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";

            if ($file['type'] == 0)
            {
                if ($temp['name'] == $entryName)
                {
                    if ($file['stat'][7] > 0)
                    {
                        $content = fread($this->archive, $file['stat'][7]);
                    }
                    else
                    {
                        $content = '';
                    }

                    break;
                }
                else
                {
                    $bytes = $file['stat'][7] + ((512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512));
                    fseek($this->archive, ftell($this->archive) + $bytes);
                }
            }

            unset ($file);
        }

        return $content;
    }

    function file_exists($entryName)
    {
        if (!$this->archive) return false;
        if (empty($entryName)) return false;

        while ($block = @fread($this->archive, 512))
        {
            $temp = unpack("a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp", $block);
            $file = array(
                'name' => $temp['prefix'] . $temp['name'],
                'stat' => array(
                    2 => $temp['mode'],
                    4 => octdec($temp['uid']),
                    5 => octdec($temp['gid']),
                    7 => octdec($temp['size']),
                    9 => octdec($temp['mtime']),
                ),
                'checksum' => octdec($temp['checksum']),
                'type' => $temp['type'],
                'magic' => $temp['magic'],
            );

            if ($file['checksum'] == 0x00000000)
                break;

            else if (substr($file['magic'], 0, 5) != "ustar")
            {
//                $this->error[] = "This script does not support extracting this type of tar file.";
                break;
            }

            $block = substr_replace($block, "        ", 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++)
                $checksum += ord(substr($block, $i, 1));
//            if ($file['checksum'] != $checksum)
//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";

            if ($file['type'] == 0)
            {
                if ($temp['name'] == $entryName)
                {
                    return true;
                }
                else
                {
                    $bytes = $file['stat'][7] + ((512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512));
                    fseek($this->archive, ftell($this->archive) + $bytes);
                }
            }

            unset ($file);
        }

        return false;
    }

    function extractTo($to)
    {
        $to = trailingslashit($to);

        while ($block = fread($this->archive, 512))
        {
            $temp = unpack("a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp", $block);
            $file = array(
                'name' => $temp['prefix'] . $temp['name'],
                'stat' => array(
                    2 => $temp['mode'],
                    4 => octdec($temp['uid']),
                    5 => octdec($temp['gid']),
                    7 => octdec($temp['size']),
                    9 => octdec($temp['mtime']),
                ),
                'checksum' => octdec($temp['checksum']),
                'type' => $temp['type'],
                'magic' => $temp['magic'],
            );

            if ($file['checksum'] == 0x00000000)
                break;
            else if (substr($file['magic'], 0, 5) != "ustar")
            {
//                $this->error[] = "This script does not support extracting this type of tar file.";
                break;
            }
            $block = substr_replace($block, "        ", 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++)
                $checksum += ord(substr($block, $i, 1));
//            if ($file['checksum'] != $checksum)
//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";

            if ($file['type'] == 5)
            {
                if (!is_dir($to . $file['name']))
                {
                    mkdir($to . $file['name'], 0777, true);
                }
            }
            else if ($file['type'] == 0)
            {
                if (!is_dir(dirname($to . $file['name'])))
                {
                    mkdir(dirname($to . $file['name']), 0777, true);
                }
                $new = @fopen($to . $file['name'], "wb+");
                $bytesToRead = $file['stat'][7];
                while ($bytesToRead > 0)
                {
                    $readNow = $bytesToRead > 1024 ? 1024 : $bytesToRead;
                    fwrite($new, fread($this->archive, $readNow));
                    $bytesToRead -= $readNow;
                }

                $toRead = (512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512);
                if ($toRead > 0)
                {
                    fread($this->archive, (512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512));
                }
                fclose($new);
            }
            unset ($file);
        }

        return null;
    }
}