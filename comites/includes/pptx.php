<?php
declare(strict_types=1);
/**
 * Generador mínimo de presentaciones PowerPoint (.pptx, Office Open XML) sin dependencias:
 * diapositivas 16:9 con rectángulos, cuadros de texto e imágenes PNG/JPG. Medidas en pulgadas.
 * Lo usa "Cifras institucionales" para descargar una presentación editable.
 */
final class ComitesPptx
{
    private const EMU = 914400;
    private const W = 12192000;
    private const H = 6858000;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var array<int,array{shapes:string[],rels:array<string,string>}> */
    private array $slides = [];
    /** @var array<string,string> ruta en el zip => contenido */
    private array $media = [];
    private int $nextId = 2;

    public function __construct(private string $titulo = 'Presentación') {}

    public function slide(): int
    {
        $this->slides[] = ['shapes' => [], 'rels' => []];
        return count($this->slides) - 1;
    }

    private static function emu(float $pulgadas): int
    {
        return (int) round($pulgadas * self::EMU);
    }

    private static function xml(string $texto): string
    {
        return htmlspecialchars(preg_replace('/[^\P{C}\n\t]/u', '', $texto) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function color(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }

    private function xfrm(float $x, float $y, float $w, float $h): string
    {
        return '<a:xfrm><a:off x="' . self::emu($x) . '" y="' . self::emu($y) . '"/><a:ext cx="' . self::emu($w) . '" cy="' . self::emu($h) . '"/></a:xfrm>';
    }

    /** Rectángulo de color (fondos, barras, franjas). $redondo usa esquinas redondeadas. */
    public function rect(int $s, float $x, float $y, float $w, float $h, string $fill, ?string $linea = null, bool $redondo = false): void
    {
        $id = $this->nextId++;
        $ln = $linea ? '<a:ln w="12700"><a:solidFill><a:srgbClr val="' . self::color($linea) . '"/></a:solidFill></a:ln>' : '<a:ln><a:noFill/></a:ln>';
        $this->slides[$s]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Forma ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'
            . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="' . ($redondo ? 'roundRect' : 'rect') . '"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . self::color($fill) . '"/></a:solidFill>' . $ln . '</p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="es-GT"/></a:p></p:txBody></p:sp>';
    }

    /**
     * Cuadro de texto. Opciones: size (pt), bold, color, align (l|ctr|r), anchor (t|ctr|b), fill,
     * font. Cada salto de línea es un párrafo; "**texto**" al inicio de un párrafo lo pone en negrita.
     */
    public function text(int $s, float $x, float $y, float $w, float $h, string $texto, array $o = []): void
    {
        $id = $this->nextId++;
        $size = (int) round(($o['size'] ?? 14) * 100);
        $color = self::color($o['color'] ?? '#2B3328');
        $font = self::xml($o['font'] ?? 'Calibri');
        $parrafos = '';
        foreach (explode("\n", $texto) as $linea) {
            $bold = !empty($o['bold']);
            if (str_starts_with($linea, '**') && str_ends_with($linea, '**') && strlen($linea) > 4) {
                $linea = substr($linea, 2, -2);
                $bold = true;
            }
            $parrafos .= '<a:p><a:pPr algn="' . ($o['align'] ?? 'l') . '"/>' . ($linea === '' ? '' : '<a:r><a:rPr lang="es-GT" sz="' . $size . '"' . ($bold ? ' b="1"' : '') . ' dirty="0">'
                . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:latin typeface="' . $font . '"/><a:cs typeface="' . $font . '"/></a:rPr><a:t>' . self::xml($linea) . '</a:t></a:r>')
                . '<a:endParaRPr lang="es-GT" sz="' . $size . '"/></a:p>';
        }
        $fill = isset($o['fill']) ? '<a:solidFill><a:srgbClr val="' . self::color($o['fill']) . '"/></a:solidFill>' : '<a:noFill/>';
        $this->slides[$s]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Texto ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr>'
            . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . $fill . '<a:ln><a:noFill/></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="27432" rIns="45720" bIns="27432" anchor="' . ($o['anchor'] ?? 't') . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>' . $parrafos . '</p:txBody></p:sp>';
    }

    /** Imagen PNG o JPG ajustada dentro del recuadro sin deformarla. $datos: bytes o data URI. */
    public function image(int $s, float $x, float $y, float $w, float $h, string $datos, string $alinear = 'ctr'): bool
    {
        if (preg_match('~^data:image/(png|jpeg);base64,(.+)$~s', $datos, $m)) {
            $datos = (string) base64_decode($m[2], true);
        }
        $info = @getimagesizefromstring($datos);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return false;
        }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
        $escala = min($w / $info[0], $h / $info[1]);
        $iw = $info[0] * $escala;
        $ih = $info[1] * $escala;
        $ix = $alinear === 'l' ? $x : ($alinear === 'r' ? $x + $w - $iw : $x + ($w - $iw) / 2);
        $iy = $y + ($h - $ih) / 2;
        $ruta = 'ppt/media/imagen' . (count($this->media) + 1) . '.' . $ext;
        $this->media[$ruta] = $datos;
        $rid = 'rId' . (count($this->slides[$s]['rels']) + 2);
        $this->slides[$s]['rels'][$rid] = '../media/' . basename($ruta);
        $id = $this->nextId++;
        $this->slides[$s]['shapes'][] = '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Imagen ' . $id . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr>' . $this->xfrm($ix, $iy, $iw, $ih)
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
        return true;
    }

    /** Contenido binario del archivo .pptx. */
    public function generar(): string
    {
        $n = count($this->slides);
        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo generar la presentación.');
        }
        $tipos = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
            . '<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $tipos .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $zip->addFromString('[Content_Types].xml', $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $tipos . '</Types>');
        $zip->addFromString('_rels/.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $fecha = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', $x . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($this->titulo) . '</dc:title><dc:creator>CENGICAÑA</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $fecha . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $fecha . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', $x . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Comités CENGICAÑA</Application><Slides>' . $n . '</Slides></Properties>');

        $ids = '';
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ids .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . (4 + $i) . '"/>';
            $rels .= '<Relationship Id="rId' . (4 + $i) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $zip->addFromString('ppt/presentation.xml', $x . '<p:presentation ' . self::NS . ' saveSubsetFonts="1"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>' . $ids . '</p:sldIdLst><p:sldSz cx="' . self::W . '" cy="' . self::H . '"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('ppt/presProps.xml', $x . '<p:presentationPr ' . self::NS . '/>');
        $zip->addFromString('ppt/tableStyles.xml', $x . '<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>');

        $grupo = '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $x . '<p:sldMaster ' . self::NS . '><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg><p:spTree>' . $grupo . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="3200"/></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/></Relationships>');
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $x . '<p:sldLayout ' . self::NS . ' type="blank" preserve="1"><p:cSld name="En blanco"><p:spTree>' . $grupo . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>');
        $zip->addFromString('ppt/theme/theme1.xml', $x . self::tema());

        foreach ($this->slides as $i => $s) {
            $num = $i + 1;
            $zip->addFromString("ppt/slides/slide{$num}.xml", $x . '<p:sld ' . self::NS . '><p:cSld><p:spTree>' . $grupo . implode('', $s['shapes']) . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $r = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';
            foreach ($s['rels'] as $rid => $destino) {
                $r .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . $destino . '"/>';
            }
            $zip->addFromString("ppt/slides/_rels/slide{$num}.xml.rels", $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $r . '</Relationships>');
        }
        foreach ($this->media as $ruta => $datos) {
            $zip->addFromString($ruta, $datos);
        }
        $zip->close();
        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $contenido;
    }

    /** Tema con los colores de la marca CENGICAÑA (aparecen en la paleta de PowerPoint al editar). */
    private static function tema(): string
    {
        $c = static fn(string $n, string $v) => "<a:{$n}><a:srgbClr val=\"{$v}\"/></a:{$n}>";
        $relleno = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $linea = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
        return '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="CENGICAÑA"><a:themeElements><a:clrScheme name="CENGICAÑA">'
            . '<a:dk1><a:srgbClr val="2B3328"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>' . $c('dk2', '1F5632') . $c('lt2', 'F4F5F6')
            . $c('accent1', '73BC25') . $c('accent2', 'A3D300') . $c('accent3', 'FFCC00') . $c('accent4', 'FF6B00') . $c('accent5', 'CED2D5') . $c('accent6', '1F5632')
            . $c('hlink', '2F5A0B') . $c('folHlink', '4A5C00') . '</a:clrScheme>'
            . '<a:fontScheme name="CENGICAÑA"><a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="CENGICAÑA"><a:fillStyleLst>' . $relleno . $relleno . $relleno . '</a:fillStyleLst><a:lnStyleLst>' . $linea . $linea . $linea . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $relleno . $relleno . $relleno . '</a:bgFillStyleLst></a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}
