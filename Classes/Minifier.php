<?php
namespace T3\Min;

/*  | This extension is made with love for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2016-2022 Armin Vieweg <info@v.ieweg.de>
 */
use \MatthiasMullie\Minify;
use T3\Min\Helper\ResourceCompressorPath;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Minifier for JS and CSS
 *
 * @package T3\Min
 */
class Minifier
{
    public const TYPE_STYLESHEET = 'CSS';
    public const TYPE_JAVASCRIPT = 'JS';

    protected ResourceCompressorPath $resourceCompressor;

    /**
     * Minifier constructor
     */
    public function __construct()
    {
        if (!class_exists(Minify\Minify::class)) {
            require_once(GeneralUtility::getFileAbsFileName('EXT:min/Resources/Private/PHP/vendor/autoload.php'));
        }
        $this->resourceCompressor = GeneralUtility::makeInstance(ResourceCompressorPath::class);
    }

    /**
     * Method called by "jsCompressHandler"
     *
     * @param array $parameters
     * @internal param \TYPO3\CMS\Core\Page\PageRenderer $pageRenderer
     */
    public function minifyJavaScript(array &$parameters): void
    {
        $parameters['jsLibs'] = $this->minifyFiles($parameters['jsLibs']);
        $parameters['jsFiles'] = $this->minifyFiles($parameters['jsFiles']);
        $parameters['jsFooterFiles'] = $this->minifyFiles($parameters['jsFooterFiles']);
        $parameters['jsInline'] = $this->minifyFiles($parameters['jsInline'], self::TYPE_JAVASCRIPT, true);
        $parameters['jsFooterInline'] = $this->minifyFiles($parameters['jsFooterInline'], self::TYPE_JAVASCRIPT, true);
    }

    /**
     * Method called by "cssCompressHandler"
     *
     * @param array $parameters
     * @internal param \TYPO3\CMS\Core\Page\PageRenderer $pageRenderer
     */
    public function minifyStylesheet(array &$parameters): void
    {
        $parameters['cssLibs'] = $this->minifyFiles($parameters['cssLibs'], self::TYPE_STYLESHEET);
        $parameters['cssFiles'] = $this->minifyFiles($parameters['cssFiles'], self::TYPE_STYLESHEET);
        $parameters['cssInline'] = $this->minifyFiles($parameters['cssInline'], self::TYPE_STYLESHEET);
    }

    /**
     * Minifies given files
     *
     * @param array $files file or inline code configuration. if "file", key contains the path.
     * @param string $type see constants in this class (JS or CSS)
     * @param bool $isInline Handles and returns minified code instead of file paths
     * @param bool $isAssetCollector
     * @return array Minified result array
     */
    public function minifyFiles(
        array $files,
        string $type = self::TYPE_JAVASCRIPT,
        bool $isInline = false,
        bool $isAssetCollector = false
    ): array {
        $filesAfterCompression = [];
        $minifierClassName = '\\MatthiasMullie\\Minify\\' . $type;

        foreach ($files as $key => $config) {
            // Do not proceed, if compression is disabled for current file
            if (!$config['compress']) {
                $filesAfterCompression[$key] = $config;
                continue;
            }

            // If key "code" is existing, this is not a file, it's inline code
            if (array_key_exists('code', $config)) {
                /** @var Minify\CSS|Minify\JS $minifier */
                $minifier = new $minifierClassName();
                if ($type === self::TYPE_STYLESHEET) {
                    $minifier->setImportExtensions([]);
                }
                $code = $config['code'];
                if ($type === self::TYPE_STYLESHEET) {
                    $code = $this->compressCss($code);
                }
                $minifier->add($code);

                $config['code'] = $minifier->minify() . ($isInline ? ';' : '');
                $config['compress'] = false;
                $filesAfterCompression[$key] = $config;
                continue;
            }

            // Process with file and build target filename for minified result
            $sitePath = $this->getSitePath();
            $targetFilename = $this->generateTargetFilename($config['file']);

            // Compress the file
            /** @var Minify\CSS|Minify\JS $minifier */
            $minifier = new $minifierClassName();
            $sourceFilePath = GeneralUtility::getFileAbsFileName($config['file']);
            if ($type === self::TYPE_STYLESHEET) {
                $minifier->setImportExtensions([]);
                $cssCode = $this->compressCss(file_get_contents($sourceFilePath));
                if (!$isInline) {
                    $relativeSourceFilePath = substr($sourceFilePath, strlen($sitePath));
                    $cssCode = $this->resourceCompressor->fixRelativeUrlPathsInCssCode($cssCode, $relativeSourceFilePath);
                }
                $minifier->add($cssCode);
            } else {
                $minifier->add($sourceFilePath);
            }

            if ($this->isGzipUsageEnabled()) {
                $minifier->gzip(
                    $sitePath . $targetFilename,
                    $GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel']
                );
            } else {
                $minifier->minify($sitePath . $targetFilename);
            }

            $config['compress'] = false;
            $config['file'] = $targetFilename;

            $filesAfterCompression[$isAssetCollector ? $key : $targetFilename] = $config;
        }
        return $filesAfterCompression;
    }

    protected function isGzipUsageEnabled(): bool
    {
        return \extension_loaded('zlib') && $GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'];
    }

    protected function getSitePath(): string
    {
        if (!Compatibility::isTypo3Version()) {
            return PATH_site;
        }
        return Environment::getPublicPath() . DIRECTORY_SEPARATOR;
    }

    protected function generateTargetFilename(string $filepath): string
    {
        $compressorPath = (string) $this->resourceCompressor;
        if (!is_dir($this->getSitePath() . $compressorPath)) {
            GeneralUtility::mkdir($this->getSitePath() . $compressorPath);
        }
        $pathInfo = pathinfo($filepath);
        $targetFilename = $compressorPath . $pathInfo['filename'] . '-min.' . $pathInfo['extension'];

        if ($this->isGzipUsageEnabled()) {
            $targetFilename .= '.gzip';
        }
        return $targetFilename;
    }

    /**
     * Applies TYPO3's default minifier to CSS code
     *
     * @param string $contents CSS code
     * @return string
     * @see \TYPO3\CMS\Core\Resource\ResourceCompressor::compressCssFile
     */
    protected function compressCss(string $contents) : string
    {
        $contents = str_replace(CR, '', $contents);
        // Strip any and all carriage returns.
        // Match and process strings, comments and everything else, one chunk at a time.
        // To understand this regex, read: "Mastering Regular Expressions 3rd Edition" chapter 6.
        $compressedContents = preg_replace_callback(
            '%
				# One-regex-to-rule-them-all! - version: 20100220_0100
				# Group 1: Match a double quoted string.
				("[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+") |  # or...
				# Group 2: Match a single quoted string.
				(\'[^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+\') |  # or...
				# Group 3: Match a regular non-MacIE5-hack comment.
				(/\\*[^\\\\*]*+\\*++(?:[^\\\\*/][^\\\\*]*+\\*++)*+/) |  # or...
				# Group 4: Match a MacIE5-type1 comment.
				(/\\*(?:[^*\\\\]*+\\**+(?!/))*+\\\\[^*]*+\\*++(?:[^*/][^*]*+\\*++)*+/(?<!\\\\\\*/)) |  # or...
				# Group 5: Match a MacIE5-type2 comment.
				(/\\*[^*]*\\*+(?:[^/*][^*]*\\*+)*/(?<=\\\\\\*/))  # folllowed by...
				# Group 6: Match everything up to final closing regular comment
				([^/]*+(?:(?!\\*)/[^/]*+)*?)
				%Ssx',
            [self::class, 'compressCssPregCallback'],
            $contents
        );

        // Do it!
        $compressedContents = preg_replace('/^\\s++/', '', $compressedContents);
        // Strip leading whitespace.
        $compressedContents = preg_replace('/[ \\t]*+\\n\\s*+/S', "\n", $compressedContents);
        // Consolidate multi-lines space.
        $compressedContents = preg_replace('/(?<!\\s)\\s*+$/S', "\n", $compressedContents);
        return $compressedContents;
    }

    /**
     * Callback function for preg_replace
     * Copy from TYPO3 CMS, where it is deprecated since version 7, and removed in version 8
     *
     * @param array $matches
     * @return string the compressed string
     * @see \TYPO3\CMS\Core\Resource\ResourceCompressor::compressCssFile
     */
    protected function compressCssPregCallback(array $matches) : string
    {
        if ($matches[1]) {
            // Group 1: Double quoted string.
            return $matches[1];
        }
        if ($matches[2]) {
            // Group 2: Single quoted string.
            return $matches[2];
        }
        if ($matches[3]) {
            // Group 3: Regular non-MacIE5-hack comment.
            return "\n";
        }
        if ($matches[4]) {
            // Group 4: MacIE5-hack-type-1 comment.
            return "\n" . '/*\\T1*/' . "\n";
        }
        if ($matches[5]) {
            // Group 5,6,7: MacIE5-hack-type-2 comment
            $matches[6] = preg_replace('/\\s++([+>{};,)])/S', '$1', $matches[6]);
            // Clean pre-punctuation.
            $matches[6] = preg_replace('/([+>{}:;,(])\\s++/S', '$1', $matches[6]);
            // Clean post-punctuation.
            $matches[6] = preg_replace('/;?\\}/S', '}' . "\n", $matches[6]);
            // Add a touch of formatting.
            return "\n" . '/*T2\\*/' . $matches[6] . "\n" . '/*T2E*/' . "\n";
        }
        if ($matches[8]) {
            // Group 8: calc function (see http://www.w3.org/TR/2006/WD-css3-values-20060919/#calc)
            return 'calc' . $matches[8];
        }
        if (isset($matches[9])) {
            // Group 9: Non-string, non-comment. Safe to clean whitespace here.
            $matches[9] = preg_replace('/^\\s++/', '', $matches[9]);
            // Strip all leading whitespace.
            $matches[9] = preg_replace('/\\s++$/', '', $matches[9]);
            // Strip all trailing whitespace.
            $matches[9] = preg_replace('/\\s{2,}+/', ' ', $matches[9]);
            // Consolidate multiple whitespace.
            $matches[9] = preg_replace('/\\s++([+>{};,)])/S', '$1', $matches[9]);
            // Clean pre-punctuation.
            $matches[9] = preg_replace('/([+>{}:;,(])\\s++/S', '$1', $matches[9]);
            // Clean post-punctuation.
            $matches[9] = preg_replace('/;?\\}/S', '}' . "\n", $matches[9]);
            // Add a touch of formatting.
            return $matches[9];
        }
        return $matches[0] . "\n" . '/* ERROR! Unexpected _proccess_css_minify() parameter */' . "\n";
    }
}
