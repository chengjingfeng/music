<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2016 - 2019
 */

namespace OCA\Music\Utility;

use OC\Hooks\PublicEmitter;

use \OCP\Files\File;
use \OCP\Files\Folder;
use \OCP\Files\IRootFolder;
use \OCP\IConfig;

use \OCA\Music\AppFramework\Core\Logger;
use \OCA\Music\BusinessLayer\ArtistBusinessLayer;
use \OCA\Music\BusinessLayer\AlbumBusinessLayer;
use \OCA\Music\BusinessLayer\TrackBusinessLayer;
use \OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use \OCA\Music\Db\Cache;
use \OCA\Music\Db\Maintenance;

use Symfony\Component\Console\Output\OutputInterface;

class Scanner extends PublicEmitter {
	private $extractor;
	private $artistBusinessLayer;
	private $albumBusinessLayer;
	private $trackBusinessLayer;
	private $playlistBusinessLayer;
	private $cache;
	private $coverHelper;
	private $logger;
	private $maintenance;
	private $configManager;
	private $appName;
	private $rootFolder;

	public function __construct(Extractor $extractor,
								ArtistBusinessLayer $artistBusinessLayer,
								AlbumBusinessLayer $albumBusinessLayer,
								TrackBusinessLayer $trackBusinessLayer,
								PlaylistBusinessLayer $playlistBusinessLayer,
								Cache $cache,
								CoverHelper $coverHelper,
								Logger $logger,
								Maintenance $maintenance,
								IConfig $configManager,
								$appName,
								IRootFolder $rootFolder) {
		$this->extractor = $extractor;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->playlistBusinessLayer = $playlistBusinessLayer;
		$this->cache = $cache;
		$this->coverHelper = $coverHelper;
		$this->logger = $logger;
		$this->maintenance = $maintenance;
		$this->configManager = $configManager;
		$this->appName = $appName;
		$this->rootFolder = $rootFolder;

		// Trying to enable stream support
		if (\ini_get('allow_url_fopen') !== '1') {
			$this->logger->log('allow_url_fopen is disabled. It is strongly advised to enable it in your php.ini', 'warn');
			@\ini_set('allow_url_fopen', '1');
		}
	}

	/**
	 * Gets called by 'post_write' (file creation, file update) and 'post_share' hooks
	 * @param \OCP\Files\File $file the file
	 * @param string $userId
	 * @param \OCP\Files\Folder $userHome
	 * @param string|null $filePath Deduced from $file if not given
	 */
	public function update($file, $userId, $userHome, $filePath = null) {
		if ($filePath === null) {
			$filePath = $file->getPath();
		}

		// debug logging
		$this->logger->log("update - $filePath", 'debug');

		if (!($file instanceof File) || !$userId || !($userHome instanceof Folder)) {
			$this->logger->log('Invalid arguments given to Scanner.update - file='.\get_class($file).
					", userId=$userId, userHome=".\get_class($userHome), 'warn');
			return;
		}

		// skip files that aren't inside the user specified path
		if (!$this->pathIsUnderMusicFolder($filePath, $userId, $userHome)) {
			$this->logger->log("skipped - file is outside of specified music folder", 'debug');
			return;
		}

		$mimetype = $file->getMimeType();

		// debug logging
		$this->logger->log("update - mimetype $mimetype", 'debug');

		if (Util::startsWith($mimetype, 'image')) {
			$this->updateImage($file, $userId);
		} elseif (Util::startsWith($mimetype, 'audio') && !self::isPlaylistMime($mimetype)) {
			$this->updateAudio($file, $userId, $userHome, $filePath, $mimetype);
		}
	}

	private static function isPlaylistMime($mime) {
		return $mime == 'audio/mpegurl' || $mime == 'audio/x-scpls';
	}

	private function pathIsUnderMusicFolder($filePath, $userId, $userHome) {
		$musicFolder = $this->getUserMusicFolder($userId, $userHome);
		$musicPath = $musicFolder->getPath();
		return Util::startsWith($filePath, $musicPath);
	}

	private function updateImage($file, $userId) {
		$coverFileId = $file->getId();
		$parentFolderId = $file->getParent()->getId();
		if ($this->albumBusinessLayer->updateFolderCover($coverFileId, $parentFolderId)) {
			$this->logger->log('updateImage - the image was set as cover for some album(s)', 'debug');
			$this->cache->remove($userId, 'collection');
		}
	}

	private function updateAudio($file, $userId, $userHome, $filePath, $mimetype) {
		if (\ini_get('allow_url_fopen')) {
			$this->emit('\OCA\Music\Utility\Scanner', 'update', [$filePath]);

			$meta = $this->extractMetadata($file, $userHome, $filePath);
			$fileId = $file->getId();

			// add/update artist and get artist entity
			$artist = $this->artistBusinessLayer->addOrUpdateArtist($meta['artist'], $userId);
			$artistId = $artist->getId();

			// add/update albumArtist and get artist entity
			$albumArtist = $this->artistBusinessLayer->addOrUpdateArtist($meta['albumArtist'], $userId);
			$albumArtistId = $albumArtist->getId();

			// add/update album and get album entity
			$album = $this->albumBusinessLayer->addOrUpdateAlbum(
					$meta['album'], $meta['discNumber'], $albumArtistId, $userId);
			$albumId = $album->getId();

			// add/update track and get track entity
			$track = $this->trackBusinessLayer->addOrUpdateTrack($meta['title'], $meta['trackNumber'], $meta['year'],
					$artistId, $albumId, $fileId, $mimetype, $userId, $meta['length'], $meta['bitrate']);

			// if present, use the embedded album art as cover for the respective album
			if ($meta['picture'] != null) {
				$this->albumBusinessLayer->setCover($fileId, $albumId);
				$this->coverHelper->removeCoverFromCache($albumId, $userId);
			}
			// if this file is an existing file which previously was used as cover for an album but now
			// the file no longer contains any embedded album art
			elseif ($this->albumBusinessLayer->albumCoverIsOneOfFiles($albumId, [$fileId])) {
				$this->albumBusinessLayer->removeCovers([$fileId]);
				$this->findEmbeddedCoverForAlbum($albumId, $userId, $userHome);
				$this->coverHelper->removeCoverFromCache($albumId, $userId);
			}

			// invalidate the cache as the music collection was changed
			$this->cache->remove($userId, 'collection');
		
			// debug logging
			$this->logger->log('imported entities - ' .
					"artist: $artistId, albumArtist: $albumArtistId, album: $albumId, track: {$track->getId()}",
					'debug');
		}
	}

	private function extractMetadata($file, $userHome, $filePath) {
		$fieldsFromFileName = self::parseFileName($file->getName());
		$fileInfo = $this->extractor->extract($file);
		$meta = [];

		// Track artist and album artist
		$meta['artist'] = ExtractorGetID3::getTag($fileInfo, 'artist');
		$meta['albumArtist'] = ExtractorGetID3::getFirstOfTags($fileInfo, ['band', 'albumartist', 'album artist', 'album_artist']);

		// use artist and albumArtist as fallbacks for each other
		if (self::isNullOrEmpty($meta['albumArtist'])) {
			$meta['albumArtist'] = $meta['artist'];
		}

		if (self::isNullOrEmpty($meta['artist'])) {
			$meta['artist'] = $meta['albumArtist'];
		}

		// set 'Unknown Artist' in case neither artist nor albumArtist was found
		if (self::isNullOrEmpty($meta['artist'])) {
			$meta['artist'] = null;
			$meta['albumArtist'] = null;
		}

		// title
		$meta['title'] = ExtractorGetID3::getTag($fileInfo, 'title');
		if (self::isNullOrEmpty($meta['title'])) {
			$meta['title'] = $fieldsFromFileName['title'];
		}

		// album
		$meta['album'] = ExtractorGetID3::getTag($fileInfo, 'album');
		if (self::isNullOrEmpty($meta['album'])) {
			// album name not set in fileinfo, use parent folder name as album name unless it is the root folder
			$dirPath = \dirname($filePath);
			if ($userHome->getPath() === $dirPath) {
				$meta['album'] = null;
			} else {
				$meta['album'] = \basename($dirPath);
			}
		}

		// track number
		$meta['trackNumber'] = ExtractorGetID3::getFirstOfTags($fileInfo, ['track_number', 'tracknumber', 'track'],
				$fieldsFromFileName['track_number']);
		$meta['trackNumber'] = self::normalizeOrdinal($meta['trackNumber']);

		// disc number
		$meta['discNumber'] = ExtractorGetID3::getFirstOfTags($fileInfo, ['discnumber', 'part_of_a_set'], '1');
		$meta['discNumber'] = self::normalizeOrdinal($meta['discNumber']);

		// year
		$meta['year'] = ExtractorGetID3::getFirstOfTags($fileInfo, ['year', 'date']);
		$meta['year'] = self::normalizeYear($meta['year']);

		$meta['picture'] = ExtractorGetID3::getTag($fileInfo, 'picture', true);

		if (\array_key_exists('playtime_seconds', $fileInfo)) {
			$meta['length'] = \ceil($fileInfo['playtime_seconds']);
		} else {
			$meta['length'] = null;
		}

		if (\array_key_exists('audio', $fileInfo) && \array_key_exists('bitrate', $fileInfo['audio'])) {
			$meta['bitrate'] = $fileInfo['audio']['bitrate'];
		} else {
			$meta['bitrate'] = null;
		}

		return $meta;
	}

	/**
	 * @param string[] $affectedUsers
	 * @param int[] $affectedAlbums
	 */
	private function invalidateCacheOnDelete($affectedUsers, $affectedAlbums) {
		// Delete may be for one file or for a folder containing thousands of albums.
		// If loads of albums got affected, then ditch the whole cache of the affected
		// users because removing the cached covers one-by-one could delay the delete
		// operation significantly.
		if (\count($affectedAlbums) > 100) {
			foreach ($affectedUsers as $user) {
				$this->cache->remove($user);
			}
		}
		else {
			// remove the cached covers
			foreach ($affectedAlbums as $albumId) {
				$this->coverHelper->removeCoverFromCache($albumId, null);
			}
			// remove the cached collection
			foreach ($affectedUsers as $user) {
				$this->cache->remove($user, 'collection');
			}
		}
	}

	/**
	 * @param int[] $fileIds
	 * @param string[]|null $userIds
	 * @return boolean true if anything was removed
	 */
	private function deleteAudio($fileIds, $userIds=null) {
		$this->logger->log('deleteAudio - '. \implode(', ', $fileIds), 'debug');
		$this->emit('\OCA\Music\Utility\Scanner', 'delete', [$fileIds, $userIds]);

		$result = $this->trackBusinessLayer->deleteTracks($fileIds, $userIds);

		if ($result) { // one or more tracks were removed
			// remove obsolete artists and albums, and track references in playlists
			$this->albumBusinessLayer->deleteById($result['obsoleteAlbums']);
			$this->artistBusinessLayer->deleteById($result['obsoleteArtists']);
			$this->playlistBusinessLayer->removeTracksFromAllLists($result['deletedTracks']);

			// check if a removed track was used as embedded cover art file for a remaining album
			foreach ($result['remainingAlbums'] as $albumId) {
				if ($this->albumBusinessLayer->albumCoverIsOneOfFiles($albumId, $fileIds)) {
					$this->albumBusinessLayer->setCover(null, $albumId);
					$this->findEmbeddedCoverForAlbum($albumId);
					$this->coverHelper->removeCoverFromCache($albumId, null);
				}
			}

			$this->invalidateCacheOnDelete($result['affectedUsers'], $result['obsoleteAlbums']);

			$this->logger->log('removed entities - ' . \json_encode($result), 'debug');
		}

		return $result !== false;
	}

	/**
	 * @param int[] $fileIds
	 * @param string[]|null $userIds
	 * @return boolean true if anything was removed
	 */
	private function deleteImage($fileIds, $userIds=null) {
		$this->logger->log('deleteImage - '. \implode(', ', $fileIds), 'debug');

		$affectedAlbums = $this->albumBusinessLayer->removeCovers($fileIds, $userIds);
		$affectedUsers = \array_map(function ($a) {
			return $a->getUserId();
		}, $affectedAlbums);
		$affectedUsers = \array_unique($affectedUsers);

		$this->invalidateCacheOnDelete($affectedUsers, Util::extractIds($affectedAlbums));

		return (\count($affectedAlbums) > 0);
	}

	/**
	 * Gets called by 'unshare' hook and 'delete' hook
	 *
	 * @param int $fileId ID of the deleted files
	 * @param string[]|null $userIds the IDs of the users to remove the file from; if omitted,
	 *                               the file is removed from all users (ie. owner and sharees)
	 */
	public function delete($fileId, $userIds=null) {
		if (!$this->deleteAudio([$fileId], $userIds) && !$this->deleteImage([$fileId], $userIds)) {
			$this->logger->log("deleted file $fileId was not an indexed " .
					'audio file or a cover image', 'debug');
		}
	}

	/**
	 * Remove all audio files and cover images in the given folder from the database.
	 * This gets called when a folder is deleted or unshared from the user.
	 *
	 * @param \OCP\Files\Folder $folder
	 * @param string[]|null $userIds the IDs of the users to remove the folder from; if omitted,
	 *                               the folder is removed from all users (ie. owner and sharees)
	 */
	public function deleteFolder($folder, $userIds=null) {
		$audioFiles = $folder->searchByMime('audio');
		if (\count($audioFiles) > 0) {
			$this->deleteAudio(Util::extractIds($audioFiles), $userIds);
		}

		// NOTE: When a folder is removed, we don't need to check for any image
		// files in the folder. This is because those images could be potentially
		// used as covers only on the audio files of the same folder and those
		// were already removed above.
	}

	/**
	 * @param string $userId
	 * @param Folder $userHome
	 * @return Folder
	 */
	public function getUserMusicFolder($userId, $userHome) {
		$musicPath = $this->configManager->getUserValue($userId, $this->appName, 'path');
		return self::getFolderFromRelativePath($userHome, $musicPath);
	}

	/**
	 * @param Folder $parentFolder
	 * @param string $relativePath
	 * @return Folder
	 */
	private static function getFolderFromRelativePath($parentFolder, $relativePath) {
		if ($relativePath !== null && $relativePath !== '/' && $relativePath !== '') {
			return $parentFolder->get($relativePath);
		} else {
			return $parentFolder;
		}
	}

	/**
	 * search for music files by mimetype inside user specified library path
	 * (which defaults to user home dir)
	 *
	 * @return \OCP\Files\File[]
	 */
	private function getMusicFiles($userId, $userHome) {
		try {
			$folder = $this->getUserMusicFolder($userId, $userHome);
		} catch (\OCP\Files\NotFoundException $e) {
			return [];
		}

		// Search files with mime 'audio/*' but filter out the playlist files
		$files = $folder->searchByMime('audio');
		return array_filter($files, function ($f) {
			return !self::isPlaylistMime($f->getMimeType());
		});
	}

	private function getScannedFiles($userId) {
		return $this->trackBusinessLayer->findAllFileIds($userId);
	}

	public function getUnscannedMusicFileIds($userId, $userHome) {
		$scannedIds = $this->getScannedFiles($userId);
		$musicFiles = $this->getMusicFiles($userId, $userHome);
		$allIds = Util::extractIds($musicFiles);
		$unscannedIds = Util::arrayDiff($allIds, $scannedIds);

		$count = \count($unscannedIds);
		if ($count) {
			$this->logger->log("Found $count unscanned music files for user $userId", 'info');
		} else {
			$this->logger->log("No unscanned music files for user $userId", 'debug');
		}

		return $unscannedIds;
	}

	public function scanFiles($userId, $userHome, $fileIds, OutputInterface $debugOutput = null) {
		$count = \count($fileIds);
		$this->logger->log("Scanning $count files of user $userId", 'debug');

		// back up the execution time limit
		$executionTime = \intval(\ini_get('max_execution_time'));
		// set execution time limit to unlimited
		\set_time_limit(0);

		$count = 0;
		foreach ($fileIds as $fileId) {
			$fileNodes = $userHome->getById($fileId);
			if (\count($fileNodes) > 0) {
				$file = $fileNodes[0];
				if ($debugOutput) {
					$before = \memory_get_usage(true);
				}
				$this->update($file, $userId, $userHome);
				if ($debugOutput) {
					$after = \memory_get_usage(true);
					$diff = $after - $before;
					$afterFileSize = new FileSize($after);
					$diffFileSize = new FileSize($diff);
					$humanFilesizeAfter = $afterFileSize->getHumanReadable();
					$humanFilesizeDiff = $diffFileSize->getHumanReadable();
					$path = $file->getPath();
					$debugOutput->writeln("\e[1m $count \e[0m $humanFilesizeAfter \e[1m $diff \e[0m ($humanFilesizeDiff) $path");
				}
				$count++;
			} else {
				$this->logger->log("File with id $fileId not found for user $userId", 'warn');
			}
		}

		// reset execution time limit
		\set_time_limit($executionTime);

		return $count;
	}

	/**
	 * Check the availability of all the indexed audio files of the user. Remove
	 * from the index any which are not available.
	 * @param string $userId
	 * @param Folder $userHome
	 * @return Number of removed files
	 */
	public function removeUnavailableFiles($userId, $userHome) {
		$indexedFiles = $this->getScannedFiles($userId);
		$unavailableFiles = [];
		foreach ($indexedFiles as $fileId) {
			$fileNodes = $userHome->getById($fileId);
			if (empty($fileNodes)) {
				$this->logger->log("File $fileId is not available for user $userId, removing", 'info');
				$unavailableFiles[] = $fileId;
			}
		}

		$count = \count($unavailableFiles);
		if ($count > 0) {
			$this->deleteAudio($unavailableFiles, [$userId]);
		}
		return $count;
	}

	/**
	 * Remove all such audio files from the collection which do not reside
	 * under the configured music path.
	 * @param string $userId
	 * @param Folder $userHome
	 */
	private function removeFilesNotUnderMusicFolder($userId, $userHome) {
		$indexedFiles = $this->getScannedFiles($userId);
		$validFiles = Util::extractIds($this->getMusicFiles($userId, $userHome));
		$filesToRemove = Util::arrayDiff($indexedFiles, $validFiles);
		if (\count($filesToRemove)) {
			$this->deleteAudio($filesToRemove, [$userId]);
		}
	}

	/**
	 * Parse and get basic info about a file. The file does not have to be indexed in the database.
	 * @param string $fileId
	 * @param string $userId
	 * @param Folder $userFolder
	 * $return array|null
	 */
	public function getFileInfo($fileId, $userId, $userFolder) {
		$info = $this->getIndexedFileInfo($fileId, $userId, $userFolder)
			?: $this->getUnindexedFileInfo($fileId, $userId, $userFolder);

		// base64-encode and wrap the cover image if available
		if ($info !== null && $info['cover'] !== null) {
			$mime = $info['cover']['mimetype'];
			$content = $info['cover']['content'];
			$info['cover'] = 'data:' . $mime. ';base64,' . \base64_encode($content);
		}

		return $info;
	}

	private function getIndexedFileInfo($fileId, $userId, $userFolder) {
		$track = $this->trackBusinessLayer->findByFileId($fileId, $userId);
		if ($track !== null) {
			$artist = $this->artistBusinessLayer->find($track->getArtistId(), $userId);
			return [
				'title'      => $track->getTitle(),
				'artist'     => $artist->getName(),
				'cover'      => $this->coverHelper->getCover($track->getAlbumId(), $userId, $userFolder),
				'in_library' => true
			];
		}
		return null;
	}

	private function getUnindexedFileInfo($fileId, $userId, $userFolder) {
		$fileNodes = $userFolder->getById($fileId);
		if (\count($fileNodes) > 0) {
			$file = $fileNodes[0];
			$metadata = $this->extractMetadata($file, $userFolder, $file->getPath());
			$cover = $metadata['picture'];
			if ($cover != null) {
				$cover = [
					'mimetype' => $cover['image_mime'],
					'content' => $this->coverHelper->scaleDownIfLarge($cover['data'], 200)
				];
			}
			return [
				'title'      => $metadata['title'],
				'artist'     => $metadata['artist'],
				'cover'      => $cover,
				'in_library' => $this->pathIsUnderMusicFolder($file->getPath(), $userId, $userFolder)
			];
		}
		return null;
	}

	/**
	 * Update music path
	 *
	 * @param string $oldPath
	 * @param string $newPath
	 * @param string $userId
	 */
	public function updatePath($oldPath, $newPath, $userId) {
		$this->logger->log("Changing music collection path of user $userId from $oldPath to $newPath", 'info');

		$userHome = $this->resolveUserFolder($userId);

		try {
			$oldFolder = self::getFolderFromRelativePath($userHome, $oldPath);
			$newFolder = self::getFolderFromRelativePath($userHome, $newPath);

			if ($newFolder->getPath() === $oldFolder->getPath()) {
				$this->logger->log('New collection path is the same as the old path, nothing to do', 'debug');
			} elseif ($newFolder->isSubNode($oldFolder)) {
				$this->logger->log('New collection path is (grand) parent of old path, previous content is still valid', 'debug');
			} elseif ($oldFolder->isSubNode($newFolder)) {
				$this->logger->log('Old collection path is (grand) parent of new path, checking the validity of previous content', 'debug');
				$this->removeFilesNotUnderMusicFolder($userId, $userHome);
			} else {
				$this->logger->log('Old and new collection paths are unrelated, erasing the previous collection content', 'debug');
				$this->maintenance->resetDb($userId);
			}
		}
		catch (\OCP\Files\NotFoundException $e) {
			$this->logger->log('One of the paths was invalid, erasing the previous collection content', 'warn');
			$this->maintenance->resetDb($userId);
		}

	}

	/**
	 * Find external cover images for albums which do not yet have one.
	 * Target either one user or all users.
	 * @param string|null $userId
	 */
	public function findCovers($userId = null) {
		$affectedUsers = $this->albumBusinessLayer->findCovers($userId);
		// scratch the cache for those users whose music collection was touched
		foreach ($affectedUsers as $user) {
			$this->cache->remove($user, 'collection');
			$this->logger->log('album cover(s) were found for user '. $user, 'debug');
		}
		return !empty($affectedUsers);
	}

	public function resolveUserFolder($userId) {
		return $this->rootFolder->getUserFolder($userId);
	}

	private static function isNullOrEmpty($string) {
		return $string === null || $string === '';
	}

	private static function normalizeOrdinal($ordinal) {
		// convert format '1/10' to '1'
		$tmp = \explode('/', $ordinal);
		$ordinal = $tmp[0];

		// check for numeric values - cast them to int and verify it's a natural number above 0
		if (\is_numeric($ordinal) && ((int)$ordinal) > 0) {
			$ordinal = (int)$ordinal;
		} else {
			$ordinal = null;
		}

		return $ordinal;
	}

	private static function parseFileName($fileName) {
		// If the file name starts e.g like "12. something" or "12 - something", the
		// preceeding number is extracted as track number. Everything after the optional
		// track number + delimiters part but before the file extension is extracted as title.
		// The file extension consists of a '.' followed by 1-4 "word characters".
		if (\preg_match('/^((\d+)\s*[.-]\s+)?(.+)\.(\w{1,4})$/', $fileName, $matches) === 1) {
			return ['track_number' => $matches[2], 'title' => $matches[3]];
		} else {
			return ['track_number' => null, 'title' => $fileName];
		}
	}

	private static function normalizeYear($date) {
		if (\ctype_digit($date)) {
			return $date; // the date is a valid year as-is
		} elseif (\preg_match('/^(\d\d\d\d)-\d\d-\d\d.*/', $date, $matches) === 1) {
			return $matches[1]; // year from ISO-formatted date yyyy-mm-dd
		} else {
			return null;
		}
	}

	/**
	 * Loop through the tracks of an album and set the first track containing embedded cover art
	 * as cover file for the album
	 * @param int $albumId
	 * @param string|null $userId name of user, deducted from $albumId if omitted
	 * @param Folder|null $userFolder home folder of user, deducted from $userId if omitted
	 */
	private function findEmbeddedCoverForAlbum($albumId, $userId=null, $userFolder=null) {
		if ($userId === null) {
			$userId = $this->albumBusinessLayer->findAlbumOwner($albumId);
		}
		if ($userFolder === null) {
			$userFolder = $this->resolveUserFolder($userId);
		}

		$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $userId);
		foreach ($tracks as $track) {
			$nodes = $userFolder->getById($track->getFileId());
			if (\count($nodes) > 0) {
				// parse the first valid node and check if it contains embedded cover art
				$image = $this->extractor->parseEmbeddedCoverArt($nodes[0]);
				if ($image != null) {
					$this->albumBusinessLayer->setCover($track->getFileId(), $albumId);
					break;
				}
			}
		}
	}
}
