<?php

namespace Illuminate\Filesystem;

use Clicalmani\Util\Files\FileNotFoundException;
use ErrorException;
use FilesystemIterator;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\MimeTypes;

class Filesystem
{
    /**
     * Determine if a file or directory exists.
     *
     * @param string  $path
     * @return bool
     */
    public function exists($path)
    {
        return file_exists($path);
    }

    /**
     * Determine if a file or directory is missing.
     *
     * @param string  $path
     * @return bool
     */
    public function missing($path)
    {
        return ! $this->exists($path);
    }

    /**
     * Get the contents of a file.
     *
     * @param string  $path
     * @param bool  $lock
     * @return string
     * @throws \Clicalmani\Util\Files\FileNotFoundException
     */
    public function get($path, $lock = false)
    {
        if ($this->isFile($path)) {
            return $lock ? $this->sharedGet($path) : file_get_contents($path);
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }

    /**
     * Get the contents of a file as decoded JSON.
     *
     * @param string  $path
     * @param int  $flags
     * @param bool  $lock
     * @return array
     * @throws \Clicalmani\Util\Files\FileNotFoundException
     */
    public function json($path, $flags = 0, $lock = false)
    {
        return json_decode($this->get($path, $lock), true, 512, $flags);
    }

    /**
     * Get contents of a file with shared access.
     *
     * @param string  $path
     * @return string
     */
    public function sharedGet($path)
    {
        $contents = '';

        $handle = fopen($path, 'rb');

        if ($handle) {
            try {
                if (flock($handle, LOCK_SH)) {
                    clearstatcache(true, $path);

                    $contents = fread($handle, $this->size($path) ?: 1);

                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        }

        return $contents;
    }

    /**
     * Get the returned value of a file.
     *
     * @param string  $path
     * @param array  $data
     * @param bool $once
     * @return mixed
     * @throws \Clicalmani\Util\Files\FileNotFoundException
     */
    public function getRequire($path, array $data = [], ?bool $once = false)
    {
        if ($this->isFile($path)) {
            return (static function () use ($path, $data, $once) {
                extract($data, EXTR_SKIP);
                return $once ? require_once $path: require $path;
            })();
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }

    /**
     * Get the contents of a file one line at a time.
     *
     * @param string  $path
     * @return iterable
     * @throws \Clicalmani\Util\Files\FileNotFoundException
     */
    public function lines($path) : iterable
    {
        if (!$this->isFile($path)) {
            throw new FileNotFoundException(
                "File does not exist at path {$path}."
            );
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        while (!$file->eof()) {
            yield $file->fgets();
        }
    }

    /**
     * Get the hash of the file at the given path.
     *
     * @param string  $path
     * @param string  $algorithm Algorithm, default md5
     * @return string|false
     */
    public function hash(string $path, ?string $algorithm = 'md5')
    {
        return hash_file($algorithm, $path);
    }

    /**
     * Write the contents of a file.
     *
     * @param string  $path
     * @param string  $contents
     * @param bool  $lock
     * @return int|bool
     */
    public function put(string $path, string $contents, ?bool $lock = false)
    {
        return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
    }

    /**
     * Write the contents of a file, replacing it atomically if it already exists.
     *
     * @param string  $path
     * @param string  $content
     * @return void
     */
    public function replace(string $path, string $content) : void
    {
        // If the path already exists and is a symlink, get the real path...
        clearstatcache(true, $path);
        $path = realpath($path) ?: $path;
        file_put_contents($path, $content, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    }

    /**
     * Replace a given string within a given file.
     *
     * @param array|string  $search
     * @param array|string  $replace
     * @param string  $path
     * @return void
     */
    public function replaceInFile(string|array $search, string|array $replace, string $path) : void
    {
        file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
    }

    /**
     * Prepend to a file.
     *
     * @param string $path
     * @param string $data
     * @return int|bool
     */
    public function prepend(string $path, string $data) : int|bool
    {
        if ($this->exists($path)) {
            return $this->put($path, $data.$this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * Append to a file.
     *
     * @param string  $path
     * @param string  $data
     * @param bool  $lock
     * @return int|bool
     */
    public function append(string $path, string $data, ?bool $lock = false) : int|bool
    {
        return file_put_contents($path, $data, FILE_APPEND | ($lock ? LOCK_EX : 0));
    }

    /**
     * Get or set UNIX mode of a file or directory.
     *
     * @param string  $path
     * @param ?int  $mode
     * @return mixed
     */
    public function chmod(string $path, ?int $mode = null) : mixed
    {
        if ($mode) {
            return chmod($path, $mode);
        }

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * Delete the file at a given path.
     *
     * @param string|array  $paths
     * @return bool
     */
    public function delete(string|array $paths) : bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;

        foreach ($paths as $path) {
            try {
                if (@unlink($path)) {
                    clearstatcache(false, $path);
                } else {
                    $success = false;
                }
            } catch (ErrorException) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Move a file to a new location.
     *
     * @param string  $path
     * @param string  $target
     * @return bool
     */
    public function move(string $path, string $target) : bool
    {
        return rename($path, $target);
    }

    /**
     * Copy a file to a new location.
     *
     * @param string  $path
     * @param string  $target
     * @return bool
     */
    public function copy(string $path, string $target) : bool
    {
        return copy($path, $target);
    }

    /**
     * Create a symlink to the target file or directory. On Windows, a hard link is created if the target is a file.
     *
     * @param string $target
     * @param string $link
     * @return ?bool
     */
    public function link(string $target, string $link) : ?bool
    {
        if (!windows_os()) {
            return symlink($target, $link);
        }

        $mode = $this->isDirectory($target) ? 'J' : 'H';

        exec("mklink /{$mode} ".escapeshellarg($link).' '.escapeshellarg($target));
    }

    /**
     * Create a relative symlink to the target file or directory.
     *
     * @param string $target
     * @param string $link
     * @return void
     * @throws \RuntimeException
     */
    public function relativeLink(string $target, string $link) : void
    {
        if (! class_exists(SymfonyFilesystem::class)) {
            throw new RuntimeException(
                'To enable support for relative links, please install the symfony/filesystem package.'
            );
        }

        $relativeTarget = (new SymfonyFilesystem)->makePathRelative($target, dirname($link));

        $this->link($this->isFile($target) ? rtrim($relativeTarget, '/') : $relativeTarget, $link);
    }

    /**
     * Extract the file name from a file path.
     *
     * @param string $path
     * @return string
     */
    public function name(string $path) : string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Extract the trailing name component from a file path.
     *
     * @param string  $path
     * @return string
     */
    public function basename(string $path) : string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * Extract the parent directory from a file path.
     *
     * @param string $path
     * @return string
     */
    public function dirname(string $path) : string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Extract the file extension from a file path.
     *
     * @param string  $path
     * @return string
     */
    public function extension(string $path) : string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Guess the file extension from the mime-type of a given file.
     *
     * @param string  $path
     * @return ?string
     * @throws \RuntimeException
     */
    public function guessExtension(string $path) : ?string
    {
        if (! class_exists(MimeTypes::class)) {
            throw new RuntimeException(
                'To enable support for guessing extensions, please install the symfony/mime package.'
            );
        }

        return (new MimeTypes)->getExtensions($this->mimeType($path))[0] ?? null;
    }

    /**
     * Get the file type of a given file.
     *
     * @param string $path
     * @return string
     */
    public function type(string $path) : string
    {
        return filetype($path);
    }

    /**
     * Get the mime-type of a given file.
     *
     * @param string $path
     * @return ?string
     */
    public function mimeType(string $path) : ?string
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }

    /**
     * Get the file size of a given file.
     *
     * @param string $path
     * @return int
     */
    public function size(string $path) : int
    {
        return filesize($path);
    }

    /**
     * Get the file's last modification time.
     *
     * @param string $path
     * @return int
     */
    public function lastModified(string $path) : int
    {
        return filemtime($path);
    }

    /**
     * Determine if the given path is a directory.
     *
     * @param string $directory
     * @return bool
     */
    public function isDirectory(string $directory) : bool
    {
        return is_dir($directory);
    }

    /**
     * Determine if the given path is a directory that does not contain any other files or directories.
     *
     * @param string $directory
     * @param bool $ignoreDotFiles
     * @return bool
     */
    public function isEmptyDirectory(string $directory, ?bool $ignoreDotFiles = false) : bool
    {
        return ! Finder::create()->ignoreDotFiles($ignoreDotFiles)->in($directory)->depth(0)->hasResults();
    }

    /**
     * Determine if the given path is readable.
     *
     * @param string  $path
     * @return bool
     */
    public function isReadable(string $path) : bool
    {
        return is_readable($path);
    }

    /**
     * Determine if the given path is writable.
     *
     * @param string $path
     * @return bool
     */
    public function isWritable(string $path) : bool
    {
        return is_writable($path);
    }

    /**
     * Determine if two files are the same by comparing their hashes.
     *
     * @param string $firstFile
     * @param string $secondFile
     * @return bool
     */
    public function hasSameHash(string $firstFile, string $secondFile) : bool
    {
        $hash = @md5_file($firstFile);

        return $hash && hash_equals($hash, (string) @md5_file($secondFile));
    }

    /**
     * Determine if the given path is a file.
     *
     * @param string  $file
     * @return bool
     */
    public function isFile(string $file) : bool
    {
        return is_file($file);
    }

    /**
     * Find path names matching a given pattern.
     *
     * @param string $pattern
     * @param int $flags
     * @return array
     */
    public function glob(string $pattern, ?int $flags = 0) : array
    {
        return glob($pattern, $flags);
    }

    /**
     * Get an array of all files in a directory.
     *
     * @param string $directory
     * @param ?bool $hidden
     * @return \Symfony\Component\Finder\SplFileInfo[]
     */
    public function files(string $directory, ?bool $hidden = false) : array
    {
        return iterator_to_array(
            Finder::create()->files()->ignoreDotFiles(! $hidden)->in($directory)->depth(0)->sortByName(),
            false
        );
    }

    /**
     * Get all of the files from the given directory (recursive).
     *
     * @param string $directory
     * @param ?bool $hidden
     * @return \Symfony\Component\Finder\SplFileInfo[]
     */
    public function allFiles(string $directory, ?bool $hidden = false) : array
    {
        return iterator_to_array(
            Finder::create()->files()->ignoreDotFiles(! $hidden)->in($directory)->sortByName(),
            false
        );
    }

    /**
     * Get all of the directories within a given directory.
     *
     * @param string  $directory
     * @return array
     */
    public function directories(string $directory) : array
    {
        $directories = [];

        foreach (Finder::create()->in($directory)->directories()->depth(0)->sortByName() as $dir) {
            $directories[] = $dir->getPathname();
        }

        return $directories;
    }

    /**
     * Ensure a directory exists.
     *
     * @param string $path
     * @param ?int $mode
     * @param ?bool $recursive
     * @return void
     */
    public function ensureDirectoryExists(string $path, ?int $mode = 0755, ?bool $recursive = true)
    {
        if (! $this->isDirectory($path)) {
            $this->makeDirectory($path, $mode, $recursive);
        }
    }

    /**
     * Create a directory.
     *
     * @param string  $path
     * @param ?int  $mode
     * @param ?bool  $recursive
     * @param ?bool  $force
     * @return bool
     */
    public function makeDirectory(string $path, ?int $mode = 0755, ?bool $recursive = false, ?bool $force = false) : bool
    {
        if ($force) {
            return @mkdir($path, $mode, $recursive);
        }

        return mkdir($path, $mode, $recursive);
    }

    /**
     * Move a directory.
     *
     * @param string $from
     * @param string $to
     * @param ?bool $overwrite
     * @return bool
     */
    public function moveDirectory(string $from, string $to, ?bool $overwrite = false) : bool
    {
        if ($overwrite && $this->isDirectory($to) && ! $this->deleteDirectory($to)) {
            return false;
        }

        return @rename($from, $to) === true;
    }

    /**
     * Copy a directory from one location to another.
     *
     * @param string $directory
     * @param string $destination
     * @param ?int $options
     * @return bool
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null) : bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $options = $options ?: FilesystemIterator::SKIP_DOTS;

        // If the destination directory does not actually exist, we will go ahead and
        // create it recursively, which just gets the destination prepared to copy
        // the files over. Once we make the directory we'll proceed the copying.
        $this->ensureDirectoryExists($destination, 0777);

        $items = new FilesystemIterator($directory, $options);

        foreach ($items as $item) {
            // As we spin through items, we will check to see if the current file is actually
            // a directory or a file. When it is actually a directory we will need to call
            // back into this function recursively to keep copying these nested folders.
            $target = $destination.'/'.$item->getBasename();

            if ($item->isDir()) {
                $path = $item->getPathname();

                if (! $this->copyDirectory($path, $target, $options)) {
                    return false;
                }
            }

            // If the current items is just a regular file, we will just copy this to the new
            // location and keep looping. If for some reason the copy fails we'll bail out
            // and return false, so the developer is aware that the copy process failed.
            elseif (! $this->copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory.
     *
     * The directory itself may be optionally preserved.
     *
     * @param string  $directory
     * @param bool  $preserve
     * @return bool
     */
    public function deleteDirectory(string $directory, ?bool $preserve = false) : bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            // If the item is a directory, we can just recurse into the function and
            // delete that sub-directory otherwise we'll just delete the file and
            // keep iterating through each file until the directory is cleaned.
            if ($item->isDir() && ! $item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            }

            // If the item is just a file, we can go ahead and delete it since we're
            // just looping through and waxing all of the files in this directory
            // and calling directories recursively, so we delete the real path.
            else {
                $this->delete($item->getPathname());
            }
        }

        unset($items);

        if (! $preserve) {
            @rmdir($directory);
        }

        return true;
    }

    /**
     * Remove all of the directories within a given directory.
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectories(string $directory) : bool
    {
        $allDirectories = $this->directories($directory);

        if (! empty($allDirectories)) {
            foreach ($allDirectories as $directoryName) {
                $this->deleteDirectory($directoryName);
            }

            return true;
        }

        return false;
    }

    /**
     * Empty the specified directory of all files and folders.
     *
     * @param string $directory
     * @return bool
     */
    public function cleanDirectory(string $directory) : bool
    {
        return $this->deleteDirectory($directory, true);
    }
}
