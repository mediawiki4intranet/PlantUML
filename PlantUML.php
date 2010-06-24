<?php

/**
 * Parser hook extension adds a <uml> tag to wiki markup for rendering UML
 * diagrams within a wiki page using PlantUML.
 *
 * Put this line near the end of your LocalSettings.php in the MediaWiki root-folder to include the extension:
 *
 * require_once('extensions/PlantUML.php');
 */

/**
 * Change $plantumlJar to match your config.
 * $plantumlJar = "c:/plantuml/plantuml.jar";
 */

/**
 * You can change the result of the getUploadDirectory() and getUploadPath()
 * if you want to put generated images somewhere else.
 * Be default, it is in the upload directory.
 */
function getUploadDirectory()
{
    global $wgUploadDirectory;
    return $wgUploadDirectory;
}

function getUploadPath()
{
    global $wgUploadPath;
    return $wgUploadPath;
}

// Make sure we are being called properly
if (!defined('MEDIAWIKI'))
{
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(-1);
}

//Avoid unstubbing $wgParser too early on modern (1.12+) MW versions, as per r35980
if (defined('MW_SUPPORTS_PARSERFIRSTCALLINIT'))
{
    $wgHooks['ParserFirstCallInit'][] = 'wfPlantUMLExtension';
    $wgHooks['ArticleSave'][] = 'cleanImages';
}
else
    $wgExtensionFunctions[] = 'wfPlantUMLExtension';

$wgExtensionCredits['parserhook'][] = array(
    'name'        => 'UML',
    'version'     => '0.1',
    'author'      => 'Roques A.',
    'url'         => 'http://www.mediawiki.org/wiki/Extension:PlantUML',
    'description' => 'Renders a UML model from text using PlantUML.'
);

function cleanImages()
{
    global $wgTitle;
    $title_hash = md5($wgTitle);
    $path = getUploadDirectory()."/generated/plantuml/uml-".$title_hash."-*.png";
    $files = glob($path);
    foreach ($files as $filename)
        unlink($filename);
    return true;
}

function wfPlantUMLExtension()
{
    global $wgParser;
    # register the extension with the WikiText parser
    # the first parameter is the name of the new tag.
    # In this case it defines the tag <uml> ... </uml>
    # the second parameter is the callback function for
    # processing the text between the tags
    $wgParser->setHook('uml', 'renderUML');
    return true;
}

/**
 * wraps a minimalistic PlantUML document around the formula and returns a string
 * containing the whole document as string.
 *
 * @param string model in PlantUML format
 * @returns minimalistic PlantUML document containing the given model
 */
function wrap_formula($PlantUML_Source)
{
    $string  = "@startuml\n";
    $string .= "$PlantUML_Source\n";
    $string .= "@enduml";
    return $string;
}

/**
 * Renders a PlantUML model by the using the following method:
 *  - write the formula into a wrapped plantuml file
 *  - Use a filename a md5 hash of the uml source
 *  - Launch PlantUML to create the PNG file into the picture cache directory
 *
 * @param string PlantUML model
 * @returns true if the picture has been successfully saved to the picture
 *          cache directory
 */
function renderPlantUML($PlantUML_Source)
{
    global $wgTitle, $plantumlJar;
    if (!$plantumlJar)
        return false;

    $PlantUML_document = wrap_formula($PlantUML_Source);

    $hash = md5($PlantUML_Source);
    $title_hash = md5($wgTitle);

    // create temporary uml text file
    if (!is_dir(getUploadDirectory()."/generated/plantuml"))
    {
        @mkdir(getUploadDirectory()."/generated");
        @mkdir(getUploadDirectory()."/generated/plantuml");
    }
    $umlFile = getUploadDirectory()."/generated/plantuml/uml-".$title_hash."-".$hash.".uml";
    $fp = fopen($umlFile,"w+");
    $w = fputs($fp,$PlantUML_document);
    fclose($fp);

    // Launch PlantUML
    // FIXME remove hardcode, although it's OK
    putenv('LANG=en_US.UTF-8');
    putenv('LC_ALL=en_US.UTF-8');
    $command = "java -jar \"".$plantumlJar."\" -o \"".getUploadDirectory()."/generated/plantuml\" \"".$umlFile."\"";
    $status_code = exec($command);

    // Delete temporary uml text file
    unlink($umlFile);

    $pngFile = getUploadDirectory()."/generated/plantuml/uml-".$title_hash."-".$hash.".png";
    if (is_file($pngFile))
        return true;

    return false;
}

/**
 * Tries to match the PlantUML given as argument against the cache.
 * If the picture has not been rendered before, it'll
 * try to render the PlantUML and drop it in the picture cache directory.
 *
 * @param string model in been format
 * @returns the webserver based URL to a picture which contains the
 * requested PlantUML model. If anything fails, the resultvalue is false.
 */
function getImageURL($PlantUML_Source)
{
    global $wgTitle;

    // Compute hash
    $title_hash = md5($wgTitle);
    $formula_hash = md5($PlantUML_Source);

    $filename = 'uml-'.$title_hash."-".$formula_hash.".png";
    $full_path_filename = getUploadDirectory()."/generated/plantuml/".$filename;

    if (is_file($full_path_filename))
        return getUploadPath()."/generated/plantuml/".$filename;
    elseif (renderPlantUML($PlantUML_Source))
        return getUploadPath()."/generated/plantuml/".$filename;
    return false;
}

# The callback function for converting the input text to HTML output
function renderUML($input, $argv)
{
    $url = getImageURL($input);
    if ($url == false)
        $text = "[An error occured in PlantUML extension]";
    else
        $text = "<img src='$url' />";
    return $text;
}
