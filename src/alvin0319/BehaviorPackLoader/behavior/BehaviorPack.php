<?php

declare(strict_types=1);

namespace alvin0319\BehaviorPackLoader\behavior;

use Ahc\Json\Comment as CommentedJsonDecoder;
use alvin0319\BehaviorPackLoader\behavior\json\BehaviorManifest;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ResourcePackException;
use function assert;
use function fclose;
use function feof;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function gettype;
use function hash_file;
use function implode;
use function preg_match;
use function strlen;

final class BehaviorPack implements ResourcePack{

	/** @var string */
	protected string $path;

	/** @var BehaviorManifest */
	protected BehaviorManifest $manifest;

	/** @var string|null */
	protected ?string $sha256 = null;

	/** @var resource */
	protected $fileResource;

	/**
	 * @param string $zipPath Path to the resource pack zip
	 * @throws ResourcePackException
	 */
	public function __construct(string $zipPath){
		$this->path = $zipPath;

		if(!file_exists($zipPath)){
			throw new ResourcePackException("File not found");
		}

		$archive = new \ZipArchive();
		if(($openResult = $archive->open($zipPath)) !== true){
			throw new ResourcePackException("Encountered ZipArchive error code $openResult while trying to open $zipPath");
		}

		if(($manifestData = $archive->getFromName("manifest.json")) === false){
			$manifestPath = null;
			$manifestIdx = null;
			for($i = 0; $i < $archive->numFiles; ++$i){
				$name = $archive->getNameIndex($i);
				if(
					($manifestPath === null or strlen($name) < strlen($manifestPath)) and
					preg_match('#.*/manifest.json$#', $name) === 1
				){
					$manifestPath = $name;
					$manifestIdx = $i;
				}
			}
			if($manifestIdx !== null){
				$manifestData = $archive->getFromIndex($manifestIdx);
				assert($manifestData !== false);
			}elseif($archive->locateName("pack_manifest.json") !== false){
				throw new ResourcePackException("Unsupported old pack format");
			}else{
				throw new ResourcePackException("manifest.json not found in the archive root");
			}
		}

		$archive->close();

		//maybe comments in the json, use stripped decoder (thanks mojang)
		try{
			$manifest = (new CommentedJsonDecoder())->decode($manifestData);
		}catch(\RuntimeException $e){
			throw new ResourcePackException("Failed to parse manifest.json: " . $e->getMessage(), $e->getCode(), $e);
		}
		if(!($manifest instanceof \stdClass)){
			throw new ResourcePackException("manifest.json should contain a JSON object, not " . gettype($manifest));
		}

		$mapper = new \JsonMapper();
		$mapper->bExceptionOnUndefinedProperty = true;
		$mapper->bExceptionOnMissingData = true;

		try{
			/** @var BehaviorManifest $manifest */
			$manifest = $mapper->map($manifest, new BehaviorManifest());
		}catch(\JsonMapper_Exception $e){
			throw new ResourcePackException("Invalid manifest.json contents: " . $e->getMessage(), 0, $e);
		}

		$this->manifest = $manifest;

		$this->fileResource = fopen($zipPath, "rb");
	}

	public function __destruct(){
		fclose($this->fileResource);
	}

	public function getPath() : string{
		return $this->path;
	}

	public function getPackName() : string{
		return $this->manifest->header->name;
	}

	public function getPackVersion() : string{
		return implode(".", $this->manifest->header->version);
	}

	public function getPackId() : string{
		return $this->manifest->header->uuid;
	}

	public function getPackSize() : int{
		return filesize($this->path);
	}

	public function getSha256(bool $cached = true) : string{
		if($this->sha256 === null or !$cached){
			$this->sha256 = hash_file("sha256", $this->path, true);
		}
		return $this->sha256;
	}

	public function getPackChunk(int $start, int $length) : string{
		fseek($this->fileResource, $start);
		if(feof($this->fileResource)){
			throw new \InvalidArgumentException("Requested a resource pack chunk with invalid start offset");
		}
		return fread($this->fileResource, $length);
	}
}