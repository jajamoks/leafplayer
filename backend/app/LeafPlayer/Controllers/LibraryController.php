<?php

namespace App\LeafPlayer\Controllers;

use App\LeafPlayer\Exceptions\Library\FolderNotFoundException;
use App\LeafPlayer\Library\Enum\LibraryActorState;
use App\LeafPlayer\Library\LibraryActor;
use Fuz\Component\SharedMemory\SharedMemory;
use Fuz\Component\SharedMemory\Storage\StorageFile;
use Illuminate\Database\Eloquent\Collection;
use \App\LeafPlayer\Models\Folder;
use App\LeafPlayer\Exceptions\Library\InvalidFolderException;
use App\LeafPlayer\Exceptions\Library\ScanInProgressException;
use App\LeafPlayer\Exceptions\Library\FolderNotAddedException;

/**
 * This controller houses all the (API-)methods to interact with the library
 *
 * Class LibraryController
 * @package App\LeafPlayer\Controllers
 */
class LibraryController extends BaseController {
    /**
     * Check folder and get information about it
     *
     * @param $path string
     * @return array
     * @throws InvalidFolderException
     */
    public function checkFolder($path) {
        $path = realpath($path);

        if (strlen($path) > 700) {
            throw new InvalidFolderException;
        }

        $exists = file_exists($path);
        $writeable = is_writeable($path);

        return collect([
            'success' => $exists && $writeable,
            'exists' => $exists,
            'writeable' => $writeable,
            'cleanPath' => $path,
            'alreadyAdded' => !Folder::where('path', $path)->get()->isEmpty()
        ]);
    }

    /**
     * Add a folder for the scanner to search for media
     *
     * @param $path string
     * @param $selected boolean
     * @return mixed
     * @throws FolderNotAddedException
     */
    public function addFolder($path, $selected) {
        $path = realpath($path);

        if (!file_exists($path) || !is_writeable($path))
            throw new FolderNotAddedException;

        return Folder::create(compact('path', 'selected'));
    }


    /**
     * Set if a folder should be included in scans or not
     *
     * @param $id integer
     * @param $selected boolean
     * @return mixed
     * @throws FolderNotFoundException
     */
    public function updateFolderSelectedState($id, $selected) {
        $folder = Folder::find($id);

        if ($folder == null) {
            throw new FolderNotFoundException($id);
        }

        $folder->selected = $selected;
        $folder->save();

        return $folder;
    }


    /**
     * Remove a folder
     *
     * @param $id integer
     * @return bool
     */
    public function removeFolder($id) {
        Folder::destroy($id);

        return true;
    }

    /**
     * Get a list of all folders currently added
     *
     * @return Collection
     */
    public function getAllFolders() {
        return Folder::all()->sortBy('updated_at')->values();
    }

    /**
     * Start scan
     *
     * @return bool
     * @throws ScanInProgressException
     */
    public function startScan() {
        if (self::isScanRunning()) {
            throw new ScanInProgressException;
        }

        return self::executeArtisanCommand('lp:library:scan --no-output');
    }

    /**
     * Clean the library
     *
     * @return bool
     */
    public function cleanLibrary() {
        return self::executeArtisanCommand('lp:library:clean --no-output');
    }

    /**
     * Wipe the library
     *
     * @return bool
     */
    public function wipeLibrary() {
        return self::executeArtisanCommand('lp:library:wipe --no-output --confirm');
    }

    /**
     * Execute artisan command asynchronously
     *
     * @param $command
     * @return bool
     */
    private static function executeArtisanCommand($command) {
        if (self::isWindows()) {
            pclose(popen('start /B cmd /C "php ' . base_path() . '/artisan ' . $command . ' >NUL 2>NUL"', 'r'));
        } else {
            exec('php ' . base_path() . '/artisan ' . $command . ' > /dev/null 2>/dev/null &');
        }

        return true;
    }

    /**
     * Check if a scan is currently in progress.
     *
     * @return bool
     */
    private static function isScanRunning() {
        $sharedScanInfo = new SharedMemory(new StorageFile(LibraryActor::getSyncFilePath()));

        return isset($sharedScanInfo->currentState) &&
            $sharedScanInfo->currentState !== LibraryActorState::FINISHED;
    }

    /**
     * Check if app is in a windows environment.
     *
     * @return bool
     */
    private static function isWindows() {
        return strncasecmp(PHP_OS, 'WIN', 3) == 0;
    }
}
