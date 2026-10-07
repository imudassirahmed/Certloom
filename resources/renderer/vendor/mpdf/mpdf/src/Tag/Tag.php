<?php

namespace CertloomVendor\Mpdf\Tag;

use CertloomVendor\Mpdf\Strict;
use CertloomVendor\Mpdf\Cache;
use CertloomVendor\Mpdf\Color\ColorConverter;
use CertloomVendor\Mpdf\CssManager;
use CertloomVendor\Mpdf\Form;
use CertloomVendor\Mpdf\Image\ImageProcessor;
use CertloomVendor\Mpdf\Language\LanguageToFontInterface;
use CertloomVendor\Mpdf\Mpdf;
use CertloomVendor\Mpdf\Otl;
use CertloomVendor\Mpdf\SizeConverter;
use CertloomVendor\Mpdf\TableOfContents;
abstract class Tag
{
    use Strict;
    /**
     * @var \Mpdf\Mpdf
     */
    protected $mpdf;
    /**
     * @var \Mpdf\Cache
     */
    protected $cache;
    /**
     * @var \Mpdf\CssManager
     */
    protected $cssManager;
    /**
     * @var \Mpdf\Form
     */
    protected $form;
    /**
     * @var \Mpdf\Otl
     */
    protected $otl;
    /**
     * @var \Mpdf\TableOfContents
     */
    protected $tableOfContents;
    /**
     * @var \Mpdf\SizeConverter
     */
    protected $sizeConverter;
    /**
     * @var \Mpdf\Color\ColorConverter
     */
    protected $colorConverter;
    /**
     * @var \Mpdf\Image\ImageProcessor
     */
    protected $imageProcessor;
    /**
     * @var \Mpdf\Language\LanguageToFontInterface
     */
    protected $languageToFont;
    const ALIGN = ['left' => 'L', 'center' => 'C', 'right' => 'R', 'top' => 'T', 'text-top' => 'TT', 'middle' => 'M', 'baseline' => 'BS', 'bottom' => 'B', 'text-bottom' => 'TB', 'justify' => 'J'];
    public function __construct(Mpdf $mpdf, Cache $cache, CssManager $cssManager, Form $form, Otl $otl, TableOfContents $tableOfContents, SizeConverter $sizeConverter, ColorConverter $colorConverter, ImageProcessor $imageProcessor, LanguageToFontInterface $languageToFont)
    {
        $this->mpdf = $mpdf;
        $this->cache = $cache;
        $this->cssManager = $cssManager;
        $this->form = $form;
        $this->otl = $otl;
        $this->tableOfContents = $tableOfContents;
        $this->sizeConverter = $sizeConverter;
        $this->colorConverter = $colorConverter;
        $this->imageProcessor = $imageProcessor;
        $this->languageToFont = $languageToFont;
    }
    public function getTagName()
    {
        $tag = get_class($this);
        return strtoupper(str_replace('Mpdf\Tag\\', '', $tag));
    }
    protected function getAlign($property)
    {
        $property = strtolower($property);
        return array_key_exists($property, self::ALIGN) ? self::ALIGN[$property] : '';
    }
    abstract public function open($attr, &$ahtml, &$ihtml);
    abstract public function close(&$ahtml, &$ihtml);
}
