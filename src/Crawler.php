<?php

/**
 * Class Crawler
 */
class Crawler
{
    /** @var $path */
    protected $path;

    /** @var array */
    protected $config = array();

    /** @var array */
    protected $folders = array();

    /** @var array */
    protected $files = array();

    /** @var int */
    protected $folders_count = 0;

    /** @var int */
    protected $files_count = 0;

    /** @var int */
    protected $total_count = 0;

    /** @var int */
    protected $page_count = 0;

    /** @var */
    protected $page;

    /** @var array */
    protected $res = array();

    /**
     * Crawler constructor.
     * @param $path
     * @param array $config
     */
    public function __construct($path, $config = array())
    {
        $this->path = $this->sanitizePath($path);
        $this->config = $config;
    }

    public function crawl() {
        try {
            $this->initImagesFolder();
            $this->checkPathExists($this->path);
            $this->listMe( $this->getFullPath($this->path) );
            $this->sendJSONResponse(true, $this->res, '');
        } catch (Exception $e) {
            $this->sendJSONResponse(false, array(), $e->getMessage());
        }
    }

    /**
     * @param $path
     * @return mixed|string
     */
    protected function sanitizePath($path) {
        // sorry, we allow only alphanumeric characters, also should be safe against traversal vulnerability
        $clean_path = preg_replace('/[^A-Za-z0-9\-\/]/', '', $path);

        //remove all empty spaces
        $clean_path = preg_replace('/\s+/', '', $clean_path);

        //explode, remove empty, implode
        $path_tokens = explode('/', $clean_path);
        $path_tokens = array_filter($path_tokens, function($value) { return $value !== ''; });
        $clean_path = implode('/', $path_tokens) . '/';

        return $clean_path;
    }

    /**
     * @param $path
     * @return array
     */
    protected function listMe($path) {
        $it = new DirectoryIterator($path);

        foreach ($it as $res) {
            if ($res->getType() == 'dir' && !$res->isDot()) {
                /** @var SDirectory $dir */
                $dir = new SDirectory($res, $path, new SThumbnail($this->config));
                $this->folders[] = $dir->toArray();
            } else if ($res->getType() == 'file' && substr($res->getFilename(), 0, 1) != '.') {
                $file = new SFile($res, $path, new SThumbnail($this->config));
                $this->files[] = $file->toArray();
            }
        }

        $this->res = $this->paginate();

        return $this->res;
    }

    /**
     * @return array
     */
    protected function paginate() {
        $this->folders_count = count($this->folders);
        $this->files_count = count($this->files);
        $this->total_count = $this->folders_count + $this->files_count;
        $this->page_count = (int)ceil($this->total_count / (int)$this->getConfig('limit'));

        if ($this->page <= 0 || $this->page > 0 && $this->page >= $this->page_count) {
            $this->page = 1;
        }

        return array_slice(array_merge($this->folders, $this->files),
            $this->getConfig('limit') * ($this->page - 1),
            $this->getConfig('limit'));
    }

    /**
     * @param $param
     * @return mixed|null
     */
    protected function getConfig($param) {
        if ( isset($this->config[$param]) ) {
            return $this->config[$param];
        }

        return null;
    }

    /**
     * We check if images folder exists as set in config: images_dir
     * If the folder doesn't exist we create it
     *
     * @return bool
     * @throws Exception
     */
    protected function initImagesFolder() {
        $images_dir = $this->getConfig('images_dir');

        if ($images_dir) {
            if (!is_dir($images_dir)) {
                mkdir($images_dir);
                chmod($images_dir, 0777);
            }
        } else {
            throw new Exception('config option: \'images_dir\' not set!');
        }

        return true;
    }

    /**
     * @param $path
     * @return string
     */
    protected function getFullPath($path) {
        $images_dir = $this->getConfig('images_dir') . '/';
        return $images_dir . $path;
    }

    /**
     * @param $path
     * @return bool
     * @throws Exception
     */
    protected function checkPathExists($path) {
        $full_path = $this->getFullPath($path);

        if (!file_exists($full_path)) {
            throw new Exception('Requested path ' . $full_path . ' doesn\'t exist');
        }

        return true;
    }


    /**
     * Send JSON formatted response
     *
     * @param bool $success
     * @param array $data
     * @param string $message
     */
    protected function sendJSONResponse($success = true, $data = array(), $message = '')
    {
        $json = $this->formatJSONResponse($success, $data, $message);

        header('Content-Type: application/json');
        echo $json;
        exit;
    }

    /**
     * Make JSON response from input data
     *
     * @param $success
     * @param $data
     * @param $message
     *
     * @return mixed|string|void
     */
    protected function formatJSONResponse($success = true, $data = array(), $message = '')
    {
        $content = array(
            'success' => $success,
            'message' => $message,
            'results' => $data,
            'page' => $this->page,
            'limit' => (int)$this->getConfig('limit'),
            'total' => $this->total_count,
            'pages' => $this->page_count,
        );

        return json_encode($content);
    }
}

/**
 * Class SResource
 */
abstract class SResource
{
    public $filename;
    public $timestamp;
    public $type_name;
    public $size;
    public $path;
    public $thumbnail;

    function __construct(SplFileInfo $res, $path, SThumbnail $thumbnail) {
        $this->filename = $res->getFilename();
        $this->timestamp = $this->getTimestamp($res);
        $this->type_name = $res->getType();
        $this->size = $res->getSize();
        $this->path = $path . $res->getFilename();
        $this->thumbnail = $thumbnail->generate($this->path);
    }

    protected function getTimestamp(SplFileInfo $res) {
        return $res->getCTime();
    }

    public abstract function toArray();
}

/**
 * Class SDirectory
 */
class SDirectory extends SResource
{
    const TYPE = 1;

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'filename' => $this->filename,
            'timestamp' => $this->timestamp,
            'type' => self::TYPE,
            'type_name' => $this->type_name,
            'size' => $this->size,
            'path' => $this->path,
            'thumbnail' => $this->thumbnail
        );
    }
}

/**
 * Class SFile
 */
class SFile extends SResource
{
    const TYPE = 2;

    public $extension;
    public $exif_data;

    function __construct(SplFileInfo $res, $path, $thumbnail)
    {
        parent::__construct($res, $path, $thumbnail);

        $this->extension = $res->getExtension();
        $this->exif_data = $this->getExifData();
    }

    protected function getExifData() {
        //TODO exif data extractir goes her.e..
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'filename' => $this->filename,
            'timestamp' => $this->timestamp,
            'type' => self::TYPE,
            'type_name' => $this->type_name,
            'size' => $this->size,
            'path' => $this->path,
            'extension' => $this->extension,
            'thumbnail' => $this->thumbnail
        );
    }
}

class SThumbnail
{
    protected $config;

    function __construct($config) {
        $this->config = $config;
    }

    /**
     * @param $param
     * @return mixed|null
     */
    protected function getConfig($param) {
        if ( isset($this->config[$param]) ) {
            return $this->config[$param];
        }

        return null;
    }

    /**
     * We check if thumbnails folder exists as set in config: thumbnails_dir
     * If the folder doesn't exist we create it
     *
     * @return bool
     * @throws Exception
     */
    protected function initThumbnailsFolder() {
        $thumbnails_dir = $this->getConfig('thumbnails_dir');

        if ($thumbnails_dir) {
            if (!is_dir($thumbnails_dir)) {
                mkdir($thumbnails_dir);
                chmod($thumbnails_dir, 0777);
            }
        } else {
            throw new Exception('config option: \'thumbnails_dir\' not set!');
        }

        return true;
    }

    protected function getInfo($filepath)
    {
        $info = getimagesize($filepath);
        return array(
            'width' => $info[0],
            'height' => $info[1],
            'type' => $info[2],
            'extension' => '');//$this->get_ext($filepath));
    }

    public function generate($filepath) {
        //$info = $this->getInfo($filepath);

        //TODO Check if file is an supported image type

        if ($info['type'] === 3)
            $image = imagecreatefrompng($filepath);
        else if ($info['type'] === 2)
            $image = imagecreatefromjpeg($filepath);
        else if ($info['type'] === 1)
            $image = imagecreatefromgif($filepath);

        $zoomw = $info['width'] / 200; //TODO move to config
        $zoomh = $info['height'] / 200; //TODO move to config;
        $zoom = ($zoomw > $zoomh) ? $zoomw : $zoomh;

        return ""; //TODO finish resizing

    }
}

