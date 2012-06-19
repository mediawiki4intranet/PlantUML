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

// Make sure we are being called properly
if (!defined('MEDIAWIKI'))
{
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(-1);
}

$wgExtensionCredits['parserhook'][] = array(
    'name'        => 'UML',
    'version'     => '0.1',
    'author'      => 'Roques A.',
    'url'         => 'http://www.mediawiki.org/wiki/Extension:PlantUML',
    'description' => 'Renders a UML model from text using PlantUML.'
);

// Avoid unstubbing $wgParser too early on modern (1.12+) MW versions, as per r35980
if (defined('MW_SUPPORTS_PARSERFIRSTCALLINIT'))
{
    $wgHooks['ParserFirstCallInit'][] = 'MW_PlantUML::init';
    $wgHooks['ArticleSave'][] = 'MW_PlantUML::cleanImages';
}
else
    $wgExtensionFunctions[] = 'MW_PlantUML::init';

class MW_PlantUML
{
    static function cleanImages()
    {
        global $wgTitle, $wgUploadDirectory;
        $title_hash = md5($wgTitle);
        $path = $wgUploadDirectory."/generated/plantuml/uml-".$title_hash."-*.png";
        $files = glob($path);
        foreach ($files as $filename)
            unlink($filename);
        return true;
    }

    static function init($parser)
    {
        # register the extension with the WikiText parser
        # the first parameter is the name of the new tag.
        # In this case it defines the tag <uml> ... </uml>
        # the second parameter is the callback function for
        # processing the text between the tags
        $parser->setHook('uml', 'MW_PlantUML::parserHook');
        return true;
    }

    /* The callback function for converting the input text to HTML output */
    static function parserHook($input, $argv)
    {
        $url = self::getImageURL($input);
        if ($url == false)
            $text = "[An error occured in PlantUML extension]";
        else
            $text = "<img src='$url' />";
        return $text;
    }

    /**
     * wraps a minimalistic PlantUML document around the formula and returns a string
     * containing the whole document as string.
     *
     * @param string model in PlantUML format
     * @returns minimalistic PlantUML document containing the given model
     */
    static function wrapFormula($PlantUML_Source)
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
    static function render($PlantUML_Source)
    {
        global $wgTitle, $plantumlJar, $wgUploadDirectory;

        $jar = $plantumlJar;
        if (!$jar)
        {
            $jar = dirname(__FILE__).'/plantuml.jar';
            if (!file_exists($jar))
                return false;
        }

        $PlantUML_document = self::wrapFormula($PlantUML_Source);

        $hash = md5($PlantUML_Source);
        $title_hash = md5($wgTitle);

        // create temporary uml text file
        if (!is_dir($wgUploadDirectory."/generated/plantuml"))
        {
            @mkdir($wgUploadDirectory."/generated");
            @mkdir($wgUploadDirectory."/generated/plantuml");
        }
        $umlFile = $wgUploadDirectory."/generated/plantuml/uml-".$title_hash."-".$hash.".uml";
        file_put_contents($umlFile, $PlantUML_document);

        // Launch PlantUML
        // FIXME remove hardcode, although it's OK
        putenv('LANG=en_US.UTF-8');
        putenv('LC_ALL=en_US.UTF-8');
        $command = "java -jar \"$jar\" -o \"$wgUploadDirectory/generated/plantuml\" \"$umlFile\"";
        $status_code = exec($command);

        // Delete temporary uml text file
        unlink($umlFile);

        $pngFile = $wgUploadDirectory."/generated/plantuml/uml-$title_hash-$hash.png";
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
    static function getImageURL($PlantUML_Source)
    {
        global $wgTitle, $wgUploadPath, $wgUploadDirectory;

        // Compute hash
        $title_hash = md5($wgTitle);
        $formula_hash = md5($PlantUML_Source);

        $filename = 'uml-'.$title_hash."-".$formula_hash.".png";
        $full_path_filename = $wgUploadDirectory."/generated/plantuml/".$filename;

        if (is_file($full_path_filename))
            return $wgUploadPath."/generated/plantuml/".$filename;
        elseif (self::render($PlantUML_Source))
            return $wgUploadPath."/generated/plantuml/".$filename;
        return false;
    }
}
