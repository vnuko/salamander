<?php

/**
 * Class Salamander
 */
class Salamander
{
    /** @var $path */
    protected $path;

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

    /** @var array */
    protected $config = array(
        'images_dir' => 'photos',
        'thumbnails_dir' => 'thumbnails',
        'cache_dir' => 'cache',
        'max_thumb_width' => 200,
        'max_thumb_height' => 200,
        'crop_mode' => false,
        'crop_resize_factor' => 2,
        'limit' => 5
    );

    /**
     * Salamander constructor.
     * @param $path
     * @param array $config
     */
    public function __construct($path, $config = array())
    {
        $this->path = $this->sanitizePath($path);
        $this->config = array_unique(array_merge($this->config, $config), SORT_REGULAR);
    }

    public function crawl()
    {
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
    protected function sanitizePath($path)
    {
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
    protected function listMe($path)
    {
        $it = new DirectoryIterator($path);

        foreach ($it as $res) {
            if ($res->getType() == 'dir' && !$res->isDot()) {
                /** @var Dir $dir */
                $dir = new Dir($res, $path);
                $this->folders[] = $dir->toArray();
            } else if ($res->getType() == 'file' && substr($res->getFilename(), 0, 1) != '.') {
                $file = new ImageFile($res, $path, ImageThumbnailGenerator::I($this->config));
                $this->files[] = $file->toArray();
            }
        }

        $this->res = $this->paginate();

        return $this->res;
    }

    /**
     * @return array
     */
    protected function paginate()
    {
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
    protected function getConfig($param)
    {
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
    protected function initImagesFolder()
    {
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
    protected function getFullPath($path)
    {
        $images_dir = $this->getConfig('images_dir') . '/';

        if($path === '/') {
            return $images_dir;
        } else {
            return $images_dir . $path;
        }
    }

    /**
     * @param $path
     * @return bool
     * @throws Exception
     */
    protected function checkPathExists($path)
    {
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
 * Resource Class
 */
abstract class Res
{
    public $name;
    public $timestamp;
    public $type_name;
    public $size;
    public $path;

    function __construct(SplFileInfo $res, $path)
    {
        $this->name = $res->getFilename();
        $this->timestamp = $this->getTimestamp($res);
        $this->type_name = $res->getType();
        $this->size = $res->getSize();
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getPathAndName()
    {
        return $this->path . $this->name;
    }

    protected function getTimestamp(SplFileInfo $res) {
        return $res->getCTime();
    }

    protected function toUrl($string) {
        if(substr($string, 0, 1) !== '/') {
            return '/' . $string;
        }

        return $string;
    }

    public abstract function toArray();
}

/**
 * Directory Class
 */
class Dir extends Res
{
    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'name' => $this->name,
            'timestamp' => $this->timestamp,
            'type_name' => $this->type_name,
            'size' => $this->size,
            'path' => $this->toUrl($this->path . $this->name)
        );
    }
}

/**
 * Image File Class
 */
class ImageFile extends Res
{
    protected $extension;
    protected $exif_data;
    protected $thumbnail;

    protected $width;
    protected $height;
    protected $type;

    function __construct(SplFileInfo $res, $path, ImageThumbnailGenerator $thumbnailGenerator)
    {
        parent::__construct($res, $path);

        $this->extension = $res->getExtension();
        $this->exif_data = $this->getExifData();

        $info = getimagesize($this->getPathAndName());
        $this->width = $info[0];
        $this->height = $info[1];
        $this->type = $info[2];

        $this->thumbnail = $thumbnailGenerator->generate($this);
    }

    protected function getExifData()
    {
        //TODO exif data extractir goes her.e..
    }

    public function getType()
    {
        return $this->type;
    }

    public function getWidth()
    {
        return $this->width;
    }

    public function getHeight()
    {
        return $this->height;
    }

    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'name' => $this->name,
            'timestamp' => $this->timestamp,
            'type_name' => $this->type_name,
            'size' => $this->size,
            'path' => $this->toUrl($this->path . $this->name),
            'extension' => $this->extension,
            'thumbnail' => $this->toUrl($this->thumbnail)
        );
    }
}

abstract class ThumbnailGenerator
{
    protected $config;

    /**
     * @param $config
     * @return null|static
     */
    public static function I($config)
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new static($config);
        }

        return $instance;
    }

    protected function __construct($config) {
        $this->config = $config;
        $this->initThumbnailsFolder();
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

    public abstract function generate($res);
}

class ImageThumbnailGenerator extends ThumbnailGenerator
{
    protected $supported_types = array('png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif');

    protected function getThumbnailFilePath($path, $filename) {
        return $this->getConfig('thumbnails_dir').'/'.filectime($path.$filename) . '_' . $filename;
    }

    /**
     * @param ImageFile $res
     * @return bool|string
     */
    public function generate($res) {
        $image_filepath = $res->getPathAndName();
        $thumbnail_filepath = $this->getThumbnailFilePath($res->getPath(), $res->getName());

        // check if thumbnail exists
        if (file_exists('./'.$thumbnail_filepath)) {
            return $thumbnail_filepath;
        }

        //check if supported file type
        if ( !isset($this->supported_types[$res->getExtension()])) {
            return false;
        }

        if ($res->getType() === 3) {
            $image = imagecreatefrompng($image_filepath);
        } else if ($res->getType() === 2) {
            $image = imagecreatefromjpeg($image_filepath);
        } else if ($res->getType() === 1) {
            $image = imagecreatefromgif($image_filepath);
        } else {
            return false;
        }

        $zoomw = $res->getWidth() / $this->getConfig('max_thumb_width');
        $zoomh = $res->getHeight() / $this->getConfig('max_thumb_height');
        $zoom = ($zoomw > $zoomh) ? $zoomw : $zoomh;

        $src_x = $src_y = 0;

        if ($res->getWidth() < $this->getConfig('max_thumb_width') && $res->getHeight() < $this->getConfig('max_thumb_height')) {
            // Preserve the original dimensions of the image is smaller than the maximum height and width
            $thumb_width = $crop_width = $res->getWidth();
            $thumb_height = $crop_height = $res->getHeight();
        } else {
            if ($this->getConfig('crop_mode')) {
                $thumb_width = $this->getConfig('max_thumb_width');
                $thumb_height = $this->getConfig('max_thumb_height');

                // The size of the image where we're going to cut the thumbnail out
                $crop_width = $res->getWidth() / $this->getConfig('crop_resize_factor');
                $crop_height = $res->getHeight() / $this->getConfig('crop_resize_factor');

                // Check if the image isn't too small
                if ($crop_width < $thumb_width && $crop_height < $thumb_height) {
                    $crop_width = $res->getWidth();
                    $crop_height = $res->getHeight();
                }

                // Choose x and y coordinates of the thumbnail
                $src_x = mt_rand(0, $crop_width - $thumb_width);
                $src_y = mt_rand(0, $crop_height - $thumb_height);
            } else {
                $thumb_width = $crop_width = $res->getWidth() / $zoom;
                $thumb_height = $crop_height = $res->getHeight() / $zoom;
            }
        }

        // Create an image for the thumbnail
        $thumbnail = imagecreatetruecolor($thumb_width, $thumb_height);

        // Preserve transparency in PNG
        if ($res->getType() === 3) {
            $alpha_color = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            imagefill($thumbnail, 0, 0, $alpha_color);
            imagesavealpha($thumbnail, true);
        } else if ($res->getType() === 1 && ($transparent_index = imagecolortransparent($image)) >= 0) {
            $transparent_color = imagecolorsforindex($image, $transparent_index);
            $transparent_index = imagecolorallocate($thumbnail, $transparent_color['red'], $transparent_color['green'],
                $transparent_color['blue']);
            imagefill($thumbnail, 0, 0, $transparent_index);
            imagecolortransparent($thumbnail, $transparent_index);
        }

        //resize
        imagecopyresampled($thumbnail, $image, 0, 0, $src_x, $src_y, $crop_width, $crop_height, $res->getWidth(), $res->getHeight());

        //save
        if ($res->getType() === 3)
            imagepng($thumbnail, $thumbnail_filepath, 85);
        else if ($res->getType() === 2)
            imagejpeg($thumbnail, $thumbnail_filepath, 85);
        else if ($res->getType() === 1)
        {
            imagetruecolortopalette($thumbnail, true, 256);
            imagegif($thumbnail, $thumbnail_filepath);
        }

        imagedestroy($image);
        imagedestroy($thumbnail);

        return $thumbnail_filepath;
    }
}

