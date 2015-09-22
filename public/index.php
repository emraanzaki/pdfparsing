<?php

/**
 * Add the autoloading mechanism of Composer
 */
require_once __DIR__.'/../vendor/autoload.php'; 

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response ;
use Symfony\Component\Process\Process;

/**
 * Create the Silex application, in which all configuration is going to go
 * @var Silex
 */
$app = new Silex\Application(); 

/**
 * App debug
 * 
 */
$app['debug'] = true;
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\FormServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider());
$app['twig.path'] = __DIR__ . '/../templates';


$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addFunction(new \Twig_SimpleFunction('asset', function ($asset) {
        return sprintf('http://localhost/parser/assets/%s', ltrim($asset, '/'));
    }));
    $twig->addFunction(new \Twig_SimpleFunction('base_url', function () {
        return 'http://localhost/parser';
    }));

    $twig->addFunction(new \Twig_SimpleFunction('imagesize', function ($path, $prop) {
        $filePath = dirname(__FILE__) . '/../uploads/images/' . $path;
        list($width, $height, $type, $attr) = $properties = getimagesize($filePath);
        
        if (in_array($prop, array('bits', 'channels', 'mime'))) {
            return $properties[$prop];
        }
        else {
            return ${$prop};    
        }

    }));

    return $twig;
}));


$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.messages' => array(),
));

function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 

    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= (1 << (10 * $pow)); 

    return round($bytes, $precision) . ' ' . $units[$pow]; 
} 

$files = array();
$dir = new DirectoryIterator(dirname( __FILE__ ) . '/../uploads/');

foreach ($dir as $fileinfo) {
   if(!in_array($fileinfo->getFilename(), array('.', '..', 'pages', 'images'))) {
        if(strpos($fileinfo->getFilename(), 'json') === FALSE) {
            $files[$fileinfo->getMTime()] = $fileinfo->getFilename();
        }
   }
}

//krsort will sort in reverse order
krsort($files);

$app['files'] = $files;

$app->match('/', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')->getForm();
    $form->handleRequest($request);
    if ($form->isValid()) {
        $data = $form->getData();
        // do something with the data
        // redirect somewhere
        return $app->redirect('/');
    }

    // display the form
    return $app['twig']->render('index.html.twig', array('form' => $form->createView(), 'files' => $app['files']));
});


$app->post('/upload', function(Silex\Application $app) {
    extract($_FILES);
    if ($file['error'])
        die("Error uploading file! code $error.\n");

    if (!empty($file)) {
        $moved = move_uploaded_file($file['tmp_name'], dirname( __FILE__ ) . '/../uploads/' . sha1(time()) . "-" . $file['name']);

        if ($moved) {
            return new Response(
                json_encode(array('message' => 'Upload Successful!')), 
                '200'
            );
        }
        else {
            return new Response(
                json_encode(array('message' => 'File upload error!')), 
                '500'
            );            
        }
    }
});



$app->get('pages/{id}', function (Silex\Application $app, $id)  { // Add a parameter for an ID in the route, and it will be supplied as argument in the function
    
    if (!array_key_exists($id, $app['files'])) {
        $app->abort(404, 'The PDF file could not be found');
    }

    $file = $app['files'][$id];
    $parser   = new \Smalot\PdfParser\Parser();
    $filepath = dirname( __FILE__ ) . '/../uploads/' . $file;

    $document = $parser->parseFile($filepath);
    $details  = $document->getDetails();

    $dir = dirname(__FILE__) . '/../uploads/pages/'.$id;
    
    if (!file_exists($dir)) {
        $dirCreate = mkdir($dir);

        for ($i = 1; $i <= $details['Pages']; $i++) {
            $fpdi = new FPDI();
            $fpdi->setSourceFile($filepath);
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $orientation = $size['h'] > $size['w'] ? 'P':'L';
            $fpdi->AddPage($orientation);
            $fpdi->useTemplate($tpl, null, null, $size['w'], $size['h'], true);

            try {
                $filename = dirname(__FILE__) . '/../uploads/pages/' . $id . '/' . $i;
                $fpdi->Output($filename.'.pdf', "F");
                $imagick = new Imagick();    
                $imagick->readimage($filename.'.pdf');
                $imagick->setImageFormat('jpeg');
                $imagick->setCompressionQuality(20);
                $imagick->writeImage($filename.'.jpg'); 
                $imagick->clear(); 
                $imagick->destroy();
            } catch (Exception $e) {
                echo 'Caught exception: ',  $e->getMessage(), "\n";
            }
        }
    }

    $jsonFile = dirname( __FILE__ ) . '/../uploads/' . $id . '.json';

    $genJSONurl = "http://$_SERVER[HTTP_HOST]/parser/public/getPDFjson/$id";

    if (!file_exists($jsonFile)) {
        file_put_contents(
            $jsonFile, 
            file_get_contents($genJSONurl)
        );
    }


    return $app['twig']->render(
        'pages.html.twig',
        array(
            'file' => $file,
            'detail' => $details,
            'size' => formatBytes(filesize($filepath)),
            'pages' => $document->getPages(),
            'docid' => $id,
        )
    );
})
->assert('id', '\d+')
->bind('single');


$app->get('pages/{id}/{pid}', function (Silex\Application $app, $id, $pid)  { // Add a parameter for an ID in the route, and it will be supplied as argument in the function

    $file = $app['files'][$id];

    $json = json_decode(
                file_get_contents(
                    dirname(__FILE__) . '/../uploads/'.$id.'.json'
                )
            );

    return $app['twig']->render(
        'page.html.twig',
        array(
            'filename' => $file,
            'pageno'   => $pid,
            'data'     => (array)$json->pages[(int) $pid - 1],
        )
    );
});


$app->get('/getPDFjson/{id}', function(Silex\Application $app, $id) {
    $file = $app['files'][$id];
    
    $filepath = dirname( __FILE__ ) . '/../uploads/' . $file;
    $imagePath = escapeshellarg(dirname( __FILE__ ) . '/../uploads/images/');
    $scriptPath = dirname(__FILE__) . '/../extractor/parser.py';

    $cmd     = $scriptPath . " " . escapeshellarg($filepath) . " '" . $imagePath . "' ";

    $process = new Process($cmd);
    $process->run();

    // executes after the command finishes
    if (!$process->isSuccessful()) {
        return new Response(
            json_encode(array('message' => $process->getErrorOutput())), 
            '500'
        );
    }

    $json = $process->getOutput();

    return new Response(
        $json, 
        '200'
    );
});

$app->run(); // Start the application, i.e. handle the request


