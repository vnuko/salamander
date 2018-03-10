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
            $this->initRootFolder();
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
     */
    protected function listMe($path) {
        $it = new DirectoryIterator($path);

        foreach ($it as $res) {
            // directories : ignore . & ..
            if ($res->getType() == 'dir' && !$res->isDot()) {
                $this->folders[] = array(
                    'filename' => $res->getFilename(),
                    'timestamp' => $res->getCTime(),
                    'extension' => $res->getExtension(),
                    'type' => 1,
                    'type_name' => 'folder',
                    'is_folder' => 1,
                    'is_file' => 0,
                    'size' => $res->getSize(),
                    'path' => $path . $res->getFilename(),
                    'thumbnail' => '');

            }
            // files : ignore files starting with .
            if ($res->getType() == 'file' && substr($res->getFilename(), 0, 1) != '.') {
                $this->files[] = array(
                    'filename' => $res->getFilename(),
                    'timestamp' => $res->getCTime(),
                    'extension' => $res->getExtension(),
                    'type' => 2,
                    'type_name' => 'file',
                    'is_folder' => 0,
                    'is_file' => 1,
                    'size' => $res->getSize(),
                    'path' => $path . $res->getFilename(),
                    'thumbnail' => '');
            }
        }

        $this->paginate();
    }

    protected function paginate() {
        $this->folders_count = count($this->folders);
        $this->files_count = count($this->files);
        $this->total_count = $this->folders_count + $this->files_count;
        $this->page_count = (int)ceil($this->total_count / (int)$this->getConfig('limit'));

        if ($this->page <= 0 || $this->page > 0 && $this->page >= $this->page_count) {
            $this->page = 1;
        }

        // array slice page file content
        $this->res = array_slice(array_merge($this->folders, $this->files),
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
     * We check if root folder exist's as set in config: root_folder
     * If the folder doesn't exist we create it
     *
     * @return bool
     * @throws Exception
     */
    protected function initRootFolder() {
        $root_folder = $this->getConfig('root_folder');

        if ($root_folder) {
            if (!is_dir($root_folder)) {
                mkdir($root_folder);
                chmod($root_folder, 0777);
            }
        } else {
            throw new Exception('config option: \'root_folder\' not set!');
        }

        return true;
    }

    /**
     * @param $path
     * @return string
     */
    protected function getFullPath($path) {
        $root_folder = $this->getConfig('root_folder') . '/';
        return $root_folder . $path;
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