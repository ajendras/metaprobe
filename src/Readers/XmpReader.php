<?php

declare(strict_types=1);

namespace MetaProbe\Readers;

use MetaProbe\ReaderInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Extracts and parses XMP metadata from JPEG APP1 segments.
 *
 * Locates the XMP packet via string search, loads it with SimpleXML,
 * registers common namespaces, and flattens relevant Dublin Core,
 * Photoshop, TIFF and XMP properties into an associative array.
 */
final class XmpReader implements ReaderInterface
{
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'image/jpeg';
    }

    /**
     * Reads XMP metadata from the image.
     *
     * @param string $path Path to the image file
     * @return array<string, mixed> Flat associative array of XMP properties
     */
    public function read(string $path): array
    {
        try {
            $xml = $this->extractXmpPacket($path);

            if ($xml === null) {
                return [];
            }

            return $this->parseXmpToArray($xml);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Extracts the XMP XML packet from the JPEG file header.
     *
     * Limits read to the first 128 KB (sufficient for metadata).
     */
    private function extractXmpPacket(string $path): ?string
    {
        $content = file_get_contents($path, false, null, 0, 128 * 1024);

        if ($content === false || $content === '') {
            return null;
        }

        $startPos = strpos($content, '<x:xmpmeta');
        if ($startPos === false) {
            $startPos = strpos($content, 'xmpmeta');
            if ($startPos === false) {
                return null;
            }
            $startPos = max(0, $startPos - 20);
        }

        $endPos = strpos($content, '</x:xmpmeta>', $startPos);
        if ($endPos === false) {
            return null;
        }

        $endPos += 12; // length of '</x:xmpmeta>'

        return substr($content, $startPos, $endPos - $startPos);
    }

    /**
     * Parses XMP XML into a flat array using XPath and registered namespaces.
     *
     * @param string $xml XMP XML fragment
     * @return array<string, mixed>
     */
    private function parseXmpToArray(string $xml): array
    {
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($xml);

        if ($sxml === false) {
            return [];
        }

        // Register all common XMP namespaces
        $namespaces = [
            'x' => 'adobe:ns:meta/',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'xmp' => 'http://ns.adobe.com/xap/1.0/',
            'xmpMM' => 'http://ns.adobe.com/xap/1.0/mm/',
            'photoshop' => 'http://ns.adobe.com/photoshop/1.0/',
            'tiff' => 'http://ns.adobe.com/tiff/1.0/',
            'exif' => 'http://ns.adobe.com/exif/1.0/',
            'Iptc4xmpCore' => 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/',
        ];

        foreach ($namespaces as $prefix => $uri) {
            $sxml->registerXPathNamespace($prefix, $uri);
        }

        $data = [];

        $xpathMap = [
            'title' => '//dc:title/rdf:Alt/rdf:li',
            'creator' => '//dc:creator/rdf:Seq/rdf:li',
            'description' => '//dc:description/rdf:Alt/rdf:li',
            'rights' => '//dc:rights/rdf:Alt/rdf:li',
            'subject' => '//dc:subject/rdf:Bag/rdf:li',
            'createDate' => '//xmp:CreateDate',
            'modifyDate' => '//xmp:ModifyDate',
            'creatorTool' => '//xmp:CreatorTool',
            'city' => '//photoshop:City',
            'state' => '//photoshop:State',
            'country' => '//photoshop:Country',
            'author' => '//photoshop:Author',
            'credit' => '//photoshop:Credit',
            'headline' => '//photoshop:Headline',
            'copyright' => '//photoshop:Copyright',
            'instructions' => '//photoshop:Instructions',
            'model' => '//tiff:Model',
            'make' => '//tiff:Make',
        ];

        foreach ($xpathMap as $key => $xpath) {
            $nodes = $sxml->xpath($xpath);
            if ($nodes !== false && count($nodes) > 0) {
                $value = (string) $nodes[0];
                if ($value !== '') {
                    $data[$key] = $value;
                }
            }
        }

        // Keywords / subject may be multiple values
        $keywords = $sxml->xpath('//dc:subject/rdf:Bag/rdf:li');
        if ($keywords !== false && count($keywords) > 0) {
            $data['keywords'] = array_map(static fn (SimpleXMLElement $node): string => (string) $node, $keywords);
        }

        // Additional common attributes on rdf:Description
        $descriptions = $sxml->xpath('//rdf:Description');
        if ($descriptions !== false && count($descriptions) > 0) {
            $desc = $descriptions[0];
            foreach (['photoshop:City', 'photoshop:Country', 'xmp:CreateDate'] as $attrName) {
                $ns = explode(':', $attrName)[0];
                $local = explode(':', $attrName)[1];
                $value = (string) ($desc->attributes($namespaces[$ns] ?? '', $local) ?? '');
                if ($value !== '' && !isset($data[strtolower($local)])) {
                    $data[strtolower($local)] = $value;
                }
            }
        }

        return $data;
    }
}
