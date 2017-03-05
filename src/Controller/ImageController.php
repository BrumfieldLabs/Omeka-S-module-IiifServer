<?php

/*
 * Copyright 2015-2017 Daniel Berthereau
 * Copyright 2015-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace IiifServer\Controller;

use \Exception;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Manager as FileManager;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Mvc\Exception\NotFoundException;
use IiifServer\IiifCreator;

/**
 * The Image controller class.
 *
 * @todo Move all OpenLayersZoom stuff in OpenLayersZoom.
 *
 * @package IiifServer
 */
class ImageController extends AbstractActionController
{
    protected $fileManager;
    protected $moduleManager;
    protected $translator;

    public function __construct(FileManager $fileManager, ModuleManager $moduleManager, TranslatorInterface $translator)
    {
        $this->fileManager = $fileManager;
        $this->moduleManager = $moduleManager;
        $this->translator = $translator;
    }

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $id = $this->params('id');
        $this->redirect()->toRoute('iiifserver_image_info', array('id' => $id));
    }

    /**
     * Returns an error 400 to requests that are invalid.
     */
    public function badAction()
    {
        $response = $this->getResponse();

        $response->setStatusCode(400);

        $view = new ViewModel;
        $view->setVariable('message', $this->translate('The IIIF server cannot fulfill the request: the arguments are incorrect.'));
        $view->setTemplate('public/image/error');

        return $view;
    }

    /**
     * Send "info.json" for the current file.
     *
     * @internal The info is managed by the ImageControler because it indicates
     * capabilities of the IIIF server for the request of a file.
     */
    public function infoAction()
    {
        $id = $this->params('id');
        if (empty($id)) {
            throw new NotFoundException;
        }

        $response = $this->api()->read('media', $id);
        $media = $response->getContent();
        if (empty($media)) {
            throw new NotFoundException;
        }

        $iiifInfo = $this->viewHelpers()->get('iiifInfo');
        $info = $iiifInfo($media, false);

        return $this->jsonLd($info);
    }

    /**
     * Returns sized image for the current file.
     */
    public function fetchAction()
    {
        $id = $this->params('id');
        $response = $this->api()->read('media', $id);
        $media = $response->getContent();
        if (empty($media)) {
            throw new NotFoundException;
        }

        $response = $this->getResponse();

        // Check if the original file is an image.
        if (strpos($media->mediaType(), 'image/') !== 0) {
            $response->setStatusCode(501);
            $view = new ViewModel;
            $view->setVariable('message', $this->translate('The source file is not an image.'));
            $view->setTemplate('public/image/error');
            return $view;
        }

        // Check, clean and optimize and fill values according to the request.
        $this->_view = new ViewModel;
        $transform = $this->_cleanRequest($media);
        if (empty($transform)) {
            // The message is set in view.
            $response->setStatusCode(400);
            $this->_view->setTemplate('public/image/error');
            return $this->_view;
        }

        $settings = $this->settings();

        // Now, process the requested transformation if needed.
        $imageUrl = '';
        $imagePath = '';

        // A quick check when there is no transformation.
        if ($transform['region']['feature'] == 'full'
                && $transform['size']['feature'] == 'full'
                && $transform['rotation']['feature'] == 'noRotation'
                && $transform['quality']['feature'] == 'default'
                && $transform['format']['feature'] == $media->mediaType()
            ) {
            $imageUrl = $media->originalUrl();
        }

        // A transformation is needed.
        else {
            // Quick check if an Omeka derivative is appropriate.
            $pretiled = $this->_useOmekaDerivative($media, $transform);
            if ($pretiled) {
                // Check if a light transformation is needed.
                if ($transform['size']['feature'] != 'full'
                        || $transform['rotation']['feature'] != 'noRotation'
                        || $transform['quality']['feature'] != 'default'
                        || $transform['format']['feature'] != $pretiled['mime_type']
                    ) {
                    $args = $transform;
                    $args['source']['filepath'] = $pretiled['filepath'];
                    $args['source']['mime_type'] = $pretiled['mime_type'];
                    $args['source']['width'] = $pretiled['width'];
                    $args['source']['height'] = $pretiled['height'];
                    $args['region']['feature'] = 'full';
                    $args['region']['x'] = 0;
                    $args['region']['y'] = 0;
                    $args['region']['width'] = $pretiled['width'];
                    $args['region']['height'] = $pretiled['height'];
                    $imagePath = $this->_transformImage($args);
                }
                // No transformation.
                else {
                    $imageUrl = $media->thumbnailUrl($pretiled['derivativeType']);
                }
            }

            // Check if another image can be used.
            else {
                // Check if the image is pre-tiled.
                $pretiled = $this->_usePreTiled($media, $transform);
                if ($pretiled) {
                    // Check if a light transformation is needed (all except
                    // extraction of the region).
                    if ($transform['rotation']['feature'] != 'noRotation'
                            || $transform['quality']['feature'] != 'default'
                            || $transform['format']['feature'] != $pretiled['mime_type']
                        ) {
                        $args = $transform;
                        $args['source']['filepath'] = $pretiled['filepath'];
                        $args['source']['mime_type'] = $pretiled['mime_type'];
                        $args['source']['width'] = $pretiled['width'];
                        $args['source']['height'] = $pretiled['height'];
                        $args['region']['feature'] = 'full';
                        $args['region']['x'] = 0;
                        $args['region']['y'] = 0;
                        $args['region']['width'] = $pretiled['width'];
                        $args['region']['height'] = $pretiled['height'];
                        $args['size']['feature'] = 'full';
                        $imagePath = $this->_transformImage($args);
                    }
                    // No transformation.
                    else {
                        $imageUrl = $pretiled['fileurl'];
                    }
                }

                // The image needs to be transformed dynamically.
                else {
                    $maxFileSize = $settings->get('iiifserver_image_max_size');
                    if (!empty($maxFileSize) && $this->_mediaFileSize($media) > $maxFileSize) {
                        $response->setStatusCode(500);
                        $view = new ViewModel;
                        $view->setVariable('message', $this->translate('The IIIF server encountered an unexpected error that prevented it from fulfilling the request: the file is not tiled for dynamic processing.'));
                        $view->setTemplate('public/image/error');
                        return $view;
                    }
                    $imagePath = $this->_transformImage($transform);
                }
            }
        }

        // Redirect to the url when an existing file is available.
        if ($imageUrl) {
            // Header for CORS, required for access of IIIF.
            $response->getHeaders()->addHeaderLine('access-control-allow-origin', '*');
            // Recommanded by feature "profileLinkHeader".
            $response->getHeaders()->addHeaderLine('Link', '<http://iiif.io/api/image/2/level2.json>;rel="profile"');
            $response->getHeaders()->addHeaderLine('Content-Type', $transform['format']['feature']);

            // Redirect (302/307) to the url of the file.
            // TODO This is a local file (normal server, except iiip server): use 200.
            return $this->redirect()->toUrl($imageUrl);
        }

        //This is a transformed file.
        elseif ($imagePath) {
            $output = file_get_contents($imagePath);
            unlink($imagePath);

            if (empty($output)) {
                $response->setStatusCode(500);
                $view = new ViewModel;
                $view->setVariable('message', $this->translate('The IIIF server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is not found or empty.'));
                $view->setTemplate('public/image/error');
                return $view;
            }

            // Header for CORS, required for access of IIIF.
            $response->getHeaders()->addHeaderLine('access-control-allow-origin', '*');
            // Recommanded by feature "profileLinkHeader".
            $response->getHeaders()->addHeaderLine('Link', '<http://iiif.io/api/image/2/level2.json>;rel="profile"');
            $response->getHeaders()->addHeaderLine('Content-Type', $transform['format']['feature']);

            $response->setContent($output);
            return $response;
        }

        // No result.
        else {
            $response->setStatusCode(500);
            $view = new ViewModel;
            $view->setVariable('message', $this->translate('The IIIF server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is empty or not found.'));
            $view->setTemplate('public/image/error');
            return $view;
        }
    }

    protected function _mediaFileSize(MediaRepresentation $media)
    {
        $filepath = $this->_mediaFilePath($media);
        return filesize($filepath);
    }

    protected function _mediaFilePath(MediaRepresentation $media, $imageType = 'original')
    {
        if ($imageType == 'original') {
            $storagePath = $this->fileManager->getStoragePath($imageType, $media->filename());
        } else {
            $basename = $this->fileManager->getBasename($media->filename());
            $storagePath = $this->fileManager->getStoragePath($imageType, $basename, FileManager::THUMBNAIL_EXTENSION);
        }
        $filepath = OMEKA_PATH . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $storagePath;

        return $filepath;
    }

    /**
     * Check, clean and optimize the request for quicker transformation.
     *
     * @todo Move the maximum of checks in ImageCreator/UniversalViewer_AbstractIiifCreator.
     *
     * @param MediaRepresentation $media
     * @return array|null Array of cleaned requested image, else null.
     */
    protected function _cleanRequest(MediaRepresentation $media)
    {
        $transform = array();

        $transform['source']['filepath'] = $this->_getImagePath($media, 'original');
        $transform['source']['mime_type'] = $media->mediaType();

        list($sourceWidth, $sourceHeight) = array_values($this->_getImageSize($media, 'original'));
        $transform['source']['width'] = $sourceWidth;
        $transform['source']['height'] = $sourceHeight;

        // The regex in the route implies that all requests are valid (no 501),
        // but may be bad formatted (400).

        $region = $this->params('region');
        $size = $this->params('size');
        $rotation = $this->params('rotation');
        $quality = $this->params('quality');
        $format = $this->params('format');

        // Determine the region.

        // Full image.
        if ($region == 'full') {
            $transform['region']['feature'] = 'full';
            // Next values may be needed for next parameters.
            $transform['region']['x'] = 0;
            $transform['region']['y'] = 0;
            $transform['region']['width'] = $sourceWidth;
            $transform['region']['height'] = $sourceHeight;
        }

        // "pct:x,y,w,h": regionByPct
        elseif (strpos($region, 'pct:') === 0) {
            $regionValues = explode(',', substr($region, 4));
            if (count($regionValues) != 4) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the region "%s" is incorrect.'), $region));
                return;
            }
            $regionValues = array_map('floatval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                    && $regionValues[1] == 0
                    && $regionValues[2] == 100
                    && $regionValues[3] == 100
                ) {
                $transform['region']['feature'] = 'full';
                // Next values may be needed for next parameters.
                $transform['region']['x'] = 0;
                $transform['region']['y'] = 0;
                $transform['region']['width'] = $sourceWidth;
                $transform['region']['height'] = $sourceHeight;
            }
            // Normal region.
            else {
                $transform['region']['feature'] = 'regionByPct';
                $transform['region']['x'] = $regionValues[0];
                $transform['region']['y'] = $regionValues[1];
                $transform['region']['width'] = $regionValues[2];
                $transform['region']['height'] = $regionValues[3];
            }
        }

        // "x,y,w,h": regionByPx.
        else {
            $regionValues = explode(',', $region);
            if (count($regionValues) != 4) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the region "%s" is incorrect.'), $region));
                return;
            }
            $regionValues = array_map('intval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                    && $regionValues[1] == 0
                    && $regionValues[2] == $sourceWidth
                    && $regionValues[3] == $sourceHeight
                ) {
                $transform['region']['feature'] = 'full';
                // Next values may be needed for next parameters.
                $transform['region']['x'] = 0;
                $transform['region']['y'] = 0;
                $transform['region']['width'] = $sourceWidth;
                $transform['region']['height'] = $sourceHeight;
            }
            // Normal region.
            else {
                $transform['region']['feature'] = 'regionByPx';
                $transform['region']['x'] = $regionValues[0];
                $transform['region']['y'] = $regionValues[1];
                $transform['region']['width'] = $regionValues[2];
                $transform['region']['height'] = $regionValues[3];
            }
        }

        // Determine the size.

        // Full image.
        if ($size == 'full') {
            $transform['size']['feature'] = 'full';
        }

        // "pct:x": sizeByPct
        elseif (strpos($size, 'pct:') === 0) {
            $sizePercentage = floatval(substr($size, 4));
            if (empty($sizePercentage) || $sizePercentage > 100) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return;
            }
            // A quick check to avoid a possible transformation.
            if ($sizePercentage == 100) {
                $transform['size']['feature'] = 'full';
            }
            // Normal size.
            else {
                $transform['size']['feature'] = 'sizeByPct';
                $transform['size']['percentage'] = $sizePercentage;
            }
        }

        // "!w,h": sizeByWh
        elseif (strpos($size, '!') === 0) {
            $pos = strpos($size, ',');
            $destinationWidth = (integer) substr($size, 1, $pos);
            $destinationHeight = (integer) substr($size, $pos + 1);
            if (empty($destinationWidth) || empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return;
            }
            // A quick check to avoid a possible transformation.
            if ($destinationWidth == $transform['region']['width']
                    && $destinationHeight == $transform['region']['width']
                ) {
                $transform['size']['feature'] = 'full';
            }
            // Normal size.
            else {
                $transform['size']['feature'] = 'sizeByWh';
                $transform['size']['width'] = $destinationWidth;
                $transform['size']['height'] = $destinationHeight;
            }
        }

        // "w,h", "w," or ",h".
        else {
            $pos = strpos($size, ',');
            $destinationWidth = (integer) substr($size, 0, $pos);
            $destinationHeight = (integer) substr($size, $pos + 1);
            if (empty($destinationWidth) && empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return;
            }

            // "w,h": sizeByWhListed or sizeByForcedWh.
            if ($destinationWidth && $destinationHeight) {
                // Check the size only if the region is full, else it's forced.
                if ($transform['region']['feature'] == 'full') {
                    $availableTypes = array('square', 'medium', 'large', 'original');
                    foreach ($availableTypes as $imageType) {
                        $filepath = $this->_getImagePath($media, $imageType);
                        if ($filepath) {
                            list($testWidth, $testHeight) = array_values($this->_getImageSize($media, $imageType));
                            if ($destinationWidth == $testWidth && $destinationHeight == $testHeight) {
                                $transform['size']['feature'] = 'full';
                                // Change the source file to avoid a transformation.
                                // TODO Check the format?
                                if ($imageType != 'original') {
                                    $transform['source']['filepath'] = $filepath;
                                    $transform['source']['mime_type'] = 'image/jpeg';
                                    $transform['source']['width'] = $testWidth;
                                    $transform['source']['height'] = $testHeight;
                                }
                                break;
                            }
                        }
                    }
                }
                if (empty($transform['size']['feature'])) {
                    $transform['size']['feature'] = 'sizeByForcedWh';
                    $transform['size']['width'] = $destinationWidth;
                    $transform['size']['height'] = $destinationHeight;
                }
            }

            // "w,": sizeByW.
            elseif ($destinationWidth && empty($destinationHeight)) {
                $transform['size']['feature'] = 'sizeByW';
                $transform['size']['width'] = $destinationWidth;
            }

            // ",h": sizeByH.
            elseif (empty($destinationWidth) && $destinationHeight) {
                $transform['size']['feature'] = 'sizeByH';
                $transform['size']['height'] = $destinationHeight;
            }

            // Not supported.
            else {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the size "%s" is not supported.'), $size));
                return;
            }

            // A quick check to avoid a possible transformation.
            if (isset($transform['size']['width']) && empty($transform['size']['width'])
                    || isset($transform['size']['height']) && empty($transform['size']['height'])
                ) {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the size "%s" is not supported.'), $size));
                return;
            }
        }

        // Determine the rotation.

        // No rotation.
        if ($rotation == '0') {
            $transform['rotation']['feature'] = 'noRotation';
        }

        // Simple rotation.
        elseif ($rotation == '90' ||$rotation == '180' || $rotation == '270')  {
            $transform['rotation']['feature'] = 'rotationBy90s';
            $transform['rotation']['degrees'] = (integer) $rotation;
        }

        // Arbitrary rotation.
        // Currently only supported with Imagick.
        else {
            $transform['rotation']['feature'] = 'rotationArbitrary';
            if (!extension_loaded('imagick') || $settings->get('iiifserver_image_creator') == 'GD') {
                $this->_view->setVariable('message', sprintf($this->translate('The IIIF server cannot fulfill the request: the rotation "%s" is not supported.'), $rotation));
                return;
            }
            $transform['rotation']['degrees'] = (float) $rotation;
        }

        // Determine the quality.
        // The regex in route checks it.
        $transform['quality']['feature'] = $quality;

        // Determine the format.
        // The regex in route checks it.
        $mimeTypes = array(
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'tif' => 'image/tiff',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'jp2' => 'image/jp2',
            'webp' => 'image/webp',
        );
        $transform['format']['feature'] = $mimeTypes[$format];

        return $transform;
    }

    /**
     * Get a pre tiled image from Omeka derivatives.
     *
     * Omeka derivative are light and basic pretiled files, that can be used for
     * a request of a full region as a fullsize.
     * @todo To be improved. Currently, thumbnails are not used.
     *
     * @param MediaRepresentation $file
     * @param array $transform
     * @return array|null Associative array with the file path, the derivative
     * type, the width and the height. Null if none.
     */
    protected function _useOmekaDerivative(MediaRepresentation $media, $transform)
    {
        // Some requirements to get tiles.
        if ($transform['region']['feature'] != 'full') {
            return;
        }

        // Check size. Here, the "full" is already checked.
        $useDerivativePath = false;

        // Currently, the check is done only on fullsize.
        $derivativeType = 'large';
        list($derivativeWidth, $derivativeHeight) = array_values($this->_getImageSize($media, $derivativeType));
        switch ($transform['size']['feature']) {
            case 'sizeByW':
            case 'sizeByH':
                $constraint = $transform['size']['feature'] == 'sizeByW'
                    ? $transform['size']['width']
                    : $transform['size']['height'];

                // Check if width is lower than fulllsize or thumbnail.
                // Omeka and IIIF doesn't use the same type of constraint, so
                // a double check is done.
                // TODO To be improved.
                if ($constraint <= $derivativeWidth || $constraint <= $derivativeHeight) {
                    $useDerivativePath = true;
                }
                break;

            case 'sizeByWh':
            case 'sizeByWhListed':
            case 'sizeByForcedWh':
                $constraintW = $transform['size']['width'];
                $constraintH = $transform['size']['height'];

                // Check if width is lower than fulllsize or thumbnail.
                if ($constraintW <= $derivativeWidth || $constraintH <= $derivativeHeight) {
                    $useDerivativePath = true;
                }
                break;

            case 'sizeByPct':
                if ($transform['size']['percentage'] <= ($derivativeWidth * 100 / $transform['source']['width'])) {
                    $useDerivativePath = true;
                }
                break;

            case 'full':
                // Not possible to use a derivative, because the region is full.
            default:
                return;
        }

        if ($useDerivativePath) {
            $derivativePath = $this->_getImagePath($media, $derivativeType);

            return array(
                'filepath' => $derivativePath,
                'derivativeType' => $derivativeType,
                'mime_type' => 'image/jpeg',
                'width' => $derivativeWidth,
                'height' => $derivativeHeight,
            );
        }
    }

    /**
     * Get a pre tiled image.
     *
     * @todo Prebuild tiles directly with the IIIF standard (same type of url).
     *
     * @internal Because the position of the requested region may be anything
     * (it depends of the client), until four images may be needed to build the
     * resulting image. It's always quicker to reassemble them rather than
     * extracting the part from the full image, specially with big ones.
     * Nevertheless, OpenSeaDragon tries to ask 0-based tiles, so only this case
     * is managed currently.
     * @todo For non standard requests, the tiled images may be used to rebuild
     * a fullsize image that is larger the Omeka derivatives. In that case,
     * multiple tiles should be joined.
     *
     * @todo If OpenLayersZoom uses an IIPImage server, it's simpler to link it
     * directly to Universal Viewer.
     *
     * @param MediaRepresentation $media
     * @param array $transform
     * @return array|null Associative array with the file path, the derivative
     * type, the width and the height. Null if none.
     */
    protected function _usePreTiled(MediaRepresentation $media, $transform)
    {
        // Some requirements to get tiles.
        if (!in_array($transform['region']['feature'], array('regionByPx', 'full'))
                || !in_array($transform['size']['feature'], array('sizeByW', 'sizeByH', 'sizeByWh', 'sizeByWhListed', 'full'))
            ) {
            return;
        }

        $module = $this->moduleManager->getModule('OpenLayersZoom');
        if (!$module || $module->getState() != ModuleManager::STATE_ACTIVE) {
            return;
        }

        // Check if the file is pretiled with the OpenLayersZoom.
        $openLayersZoom = $this->viewHelpers()->get('openLayersZoom');
        if (!$openLayersZoom->isZoomed($media)) {
            return;
        }

        // Get the level and position x and y of the tile.
        $data = $this->_getLevelAndPosition($media, $transform['source'], $transform['region'], $transform['size']);
        if (is_null($data)) {
            return;
        }

        // Determine the tile group.
        $tileGroup = $this->_getTileGroup(array(
                'width' => $transform['source']['width'],
                'height' => $transform['source']['height'],
            ), $data);
        if (is_null($tileGroup)) {
            return;
        }

        // Set the image path.
        $olz = new OpenLayersZoom_Creator();
        // The use of a full url avoids some complexity when Omeka is not
        // the root of the server.
        $dirWeb = $olz->getZDataWeb($media, true);
        $dirpath = $olz->useIIPImageServer()
            ? $dirWeb
            : $olz->getZDataDir($media);
        $path = sprintf('/TileGroup%d/%d-%d-%d.jpg',
            $tileGroup, $data['level'], $data['x'] , $data['y']);
        // The imageUrl is used when there is no transformation.
        $imageUrl = $dirWeb . $path;
        $imagePath = $dirpath . $path;
        $derivativeType = 'zoom_tiles';

        list($tileWidth, $tileHeight) = array_values($this-> _getWidthAndHeight($imagePath));

        $return = array(
            'fileurl' => $imageUrl,
            'filepath' => $imagePath,
            'derivativeType' => $derivativeType,
            'mime_type' => 'image/jpeg',
            'width' => $tileWidth,
            'height' => $tileHeight,
        );
        return $return;
    }

    /**
     * Get the level and the position of the cell from the source and region.
     *
     * @internal OpenLayersZoom uses Zoomify format, that uses square tiles.
     * @return array|null
     */
    protected function _getLevelAndPosition(MediaRepresentation $media, $source, $region, $size)
    {
        //Get the properties.
        $tileProperties = $this->_getTileProperties($media);
        if (empty($tileProperties)) {
            return;
        }

        // Check if the tile may be cropped.
        $isFirstColumn = $region['x'] == 0;
        $isFirstRow = $region['y'] == 0;
        $isFirstCell = $isFirstColumn && $isFirstRow;
        $isLastColumn = $source['width'] == ($region['x'] + $region['width']);
        $isLastRow = $source['height'] == ($region['y'] + $region['height']);
        $isLastCell = $isLastColumn && $isLastRow;

        // Cell size is 256 by default because it's hardcoded in OpenLayersZoom.
        // TODO Use the true size, in particular for non standard requests.
        // Furthermore, a bigger size can be requested directly, and, in that
        // case, multiple tiles should be joined.
        $cellSize = 256;

        // Manage the base level.
        if ($isFirstCell && $isLastCell) {
            // Check if the tile size is the one requested.
            $level = 0;
            $cellX = 0;
            $cellY = 0;
        }

        // Else normal region.
        else {
            // Determine the position of the cell from the source and the
            // region.
            switch ($size['feature']) {
                case 'sizeByW':
                    if ($isLastColumn) {
                        // Normal row. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use row, because Zoomify tiles are square.
                            $count = (integer) ceil(max($source['width'], $source['height']) / $region['height']);
                            $cellX = $region['x'] / $region['height'];
                            $cellY = $region['y'] / $region['height'];
                        }
                    }
                    // Normal column and normal region.
                    else {
                        $count = (integer) ceil(max($source['width'], $source['height']) / $region['width']);
                        $cellX = $region['x'] / $region['width'];
                        $cellY = $region['y'] / $region['width'];
                    }
                    break;

                case 'sizeByH':
                    if ($isLastRow) {
                        // Normal column. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use column, because Zoomify tiles are square.
                            $count = (integer) ceil(max($source['width'], $source['height']) / $region['width']);
                            $cellX = $region['x'] / $region['width'];
                            $cellY = $region['y'] / $region['width'];
                        }
                    }
                    // Normal row and normal region.
                    else {
                        $count = (integer) ceil(max($source['width'], $source['height']) / $region['height']);
                        $cellX = $region['x'] / $region['height'];
                        $cellY = $region['y'] / $region['height'];
                    }
                    break;

                case 'sizeByWh':
                case 'sizeByWhListed':
                    // TODO To improve.
                    if ($isLastColumn) {
                        // Normal row. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use row, because Zoomify tiles are square.
                            $count = (integer) ceil(max($source['width'], $source['height']) / $region['height']);
                            $cellX = $region['x'] / $region['width'];
                            $cellY = $region['y'] / $region['height'];
                        }
                    }
                    // Normal column and normal region.
                    else {
                        $count = (integer) ceil(max($source['width'], $source['height']) / $region['width']);
                        $cellX = $region['x'] / $region['width'];
                        $cellY = $region['y'] / $region['height'];
                    }
                    break;

                case 'full':
                    // TODO To be checked.
                    // Normalize the size, but they can be cropped.
                    $size['width'] = $region['width'];
                    $size['height'] = $region['height'];
                    $count = (integer) ceil(max($source['width'], $source['height']) / $region['width']);
                    $cellX = $region['x'] / $region['width'];
                    $cellY = $region['y'] / $region['height'];
                    break;

                default:
                    return;
            }

            // Get the list of squale factors.
            $squaleFactors = array();
            $maxSize = max($source['width'], $source['height']);
            $total = (integer) ceil($maxSize / $tileProperties['size']);
            $factor = 1;
            // If level is set, count is not set and useless.
            $level = isset($level) ? $level : 0;
            $count = isset($count) ? $count : 0;
            while ($factor / 2 < $total) {
                // This allows to determine the level for normal regions.
                if ($factor < $count) {
                    $level++;
                }
                $squaleFactors[] = $factor;
                $factor = $factor * 2;
            }

            // Process the last cell, an exception because it may be cropped.
            if ($isLastCell) {
                // TODO Quick check if the last cell is a standard one (not cropped)?

                // Because the default size of the region lacks, it is
                // simpler to check if an image of the zoomed file is the
                // same using the tile size from properties, for each
                // possible factor.
                $reversedSqualeFactors = array_reverse($squaleFactors);
                $isLevelFound = false;
                foreach ($reversedSqualeFactors as $level => $reversedFactor) {
                    $tileFactor = $reversedFactor * $tileProperties['size'];
                    $countX = (integer) ceil($source['width'] / $tileFactor);
                    $countY = (integer) ceil($source['height'] / $tileFactor);
                    $lastRegionWidth = $source['width'] - (($countX -1) * $tileFactor);
                    $lastRegionHeight = $source['height'] - (($countY - 1) * $tileFactor);
                    $lastRegionX = $source['width'] - $lastRegionWidth;
                    $lastRegionY = $source['height'] - $lastRegionHeight;
                    if ($region['x'] == $lastRegionX
                            && $region['y'] == $lastRegionY
                            && $region['width'] == $lastRegionWidth
                            && $region['height'] == $lastRegionHeight
                        ) {
                        // Level is found.
                        $isLevelFound = true;
                        // Cells are 0-based.
                        $cellX = $countX - 1;
                        $cellY = $countY - 1;
                        break;
                    }
                }
                if (!$isLevelFound) {
                    return;
                }
            }
        }

        // TODO Check the size requirement.
        // Currently, this is not done, because the size is hardcoded in
        // OpenLayersZoom.

        $checkedTileSize = $this->_checkTileSize($media, $cellSize, $tileProperties['size']);
        if ($checkedTileSize) {
            return array(
                'level' => $level,
                'x' => $cellX,
                'y' => $cellY,
                'size' => $tileProperties['size'],
            );
        }
    }

    /**
     * Return the tile group of a tile from level, position and size.
     *
     * @see https://github.com/openlayers/ol3/blob/master/src/ol/source/zoomifysource.js
     *
     * @param array $image
     * @param array $tile
     * @return integer|null
     */
    protected function _getTileGroup($image, $tile)
    {
        if (empty($image) || empty($tile)) {
            return;
        }

        $tierSizeCalculation = 'default';
        // $tierSizeCalculation = 'truncated';

        $tierSizeInTiles = array();

        switch ($tierSizeCalculation) {
            case 'default':
                $tileSize = $tile['size'];
                while ($image['width'] > $tileSize || $image['height'] > $tileSize) {
                    $tierSizeInTiles[] = array(
                        ceil($image['width'] / $tileSize),
                        ceil($image['height'] / $tileSize),
                    );
                    $tileSize += $tileSize;
                }
                break;

            case 'truncated':
                $width = $image['width'];
                $height = $image['height'];
                while ($width > $tile['size'] || $height > $tile['size']) {
                    $tierSizeInTiles[] = array(
                        ceil($width / $tile['size']),
                        ceil($height / $tile['size']),
                    );
                    $width >>= 1;
                    $height >>= 1;
                }
                break;

            default:
                return;
        }

        $tierSizeInTiles[] = array(1, 1);
        $tierSizeInTiles = array_reverse($tierSizeInTiles);

        $resolutions = array(1);
        $tileCountUpToTier = array(0);
        for ($i = 1, $ii = count($tierSizeInTiles); $i < $ii; $i++) {
            $resolutions[] = 1 << $i;
            $tileCountUpToTier[] =
                $tierSizeInTiles[$i - 1][0] * $tierSizeInTiles[$i - 1][1]
                + $tileCountUpToTier[$i - 1];
        }

        $tileIndex = $tile['x']
            + $tile['y'] * $tierSizeInTiles[$tile['level']][0]
            + $tileCountUpToTier[$tile['level']];
        $tileGroup = ($tileIndex / $tile['size']) ?: 0;
        return $tileGroup;
    }

    /**
     * Return the properties of a tiled file.
     *
     * @return array|null
     * @see UniversalViewer_View_Helper_IiifManifest::_getTileProperties()
     */
    protected function _getTileProperties(MediaRepresentation $media)
    {
        $olz = new OpenLayersZoom_Creator();
        $dirpath = $olz->useIIPImageServer()
            ? $olz->getZDataWeb($media)
            : $olz->getZDataDir($media);
        $properties = simplexml_load_file($dirpath . '/ImageProperties.xml', 'SimpleXMLElement', LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_PARSEHUGE);
        if ($properties === false) {
            return;
        }
        $properties = $properties->attributes();
        $properties = reset($properties);

        // Standardize the properties.
        $result = array();
        $result['size'] = (integer) $properties['TILESIZE'];
        $result['total'] = (integer) $properties['NUMTILES'];
        $result['source']['width'] = (integer) $properties['WIDTH'];
        $result['source']['height'] = (integer) $properties['HEIGHT'];
        return $result;
    }

    /**
     * Check if the size is the one requested.
     *
     * @internal The tile may be cropped.
     * @param File $file
     * @param integer $tileSize This is the standard size, not cropped.
     * @param integer $tilePropertiesSize
     * @return boolean
     */
    protected function _checkTileSize(MediaRepresentation $media, $tileSize, $tilePropertiesSize = null)
    {
        if (is_null($tilePropertiesSize)) {
            $tileProperties = $this->_getTileProperties($media);
            if (empty($tileProperties)) {
                return false;
            }
            $tilePropertiesSize = $tileProperties['size'];
        }

        return $tileSize == $tilePropertiesSize;
    }

    /**
     * Transform a file according to parameters.
     *
     * @param array $args Contains the filepath and the parameters.
     * @return string|null The filepath to the temp image if success.
     */
    protected function _transformImage($args)
    {
        $creator = new IiifCreator($this->fileManager, $this->settings());
        $creator->setLogger($this->logger());
        $creator->setTranslator($this->translator);

        return $creator->transform($args);
    }

    /**
     * Get an array of the width and height of the image file.
     *
     * @param MediaRepresentation $media
     * @param string $imageType
     * @return array Associative array of width and height of the image file.
     * If the file is not an image, the width and the height will be null.
     *
     * @see UniversalViewer_View_Helper_IiifManifest::_getImageSize()
     * @see UniversalViewer_View_Helper_IiifInfo::_getImageSize()
     * @see UniversalViewer_ImageController::_getImageSize()
     * @todo Refactorize.
     */
    protected function _getImageSize(MediaRepresentation $media, $imageType = 'original')
    {
        // Check if this is an image.
        if (empty($media) || strpos($media->mediaType(), 'image/') !== 0) {
            return array(
                'width' => null,
                'height' => null,
            );
        }

        // The storage adapter should be checked for external storage.
        if ($imageType == 'original') {
            $storagePath = $this->fileManager->getStoragePath($imageType, $media->filename());
        } else {
            $basename = $this->fileManager->getBasename($media->filename());
            $storagePath = $this->fileManager->getStoragePath($imageType, $basename, FileManager::THUMBNAIL_EXTENSION);
        }
        $filepath = OMEKA_PATH . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $storagePath;
        $result = $this->_getWidthAndHeight($filepath);

        if (empty($result['width']) || empty($result['height'])) {
            throw new Exception("Failed to get image resolution: $filepath");
        }

        return $result;
    }

    /**
     * Get the path to an original or derivative file for an image.
     *
     * @param File $file
     * @param string $derivativeType
     * @return string|null Null if not exists.
     * @see UniversalViewer_View_Helper_IiifInfo::_getImagePath()
     */
    protected function _getImagePath($media, $derivativeType = 'original')
    {
        // Check if the file is an image.
        if (strpos($media->mediaType(), 'image/') === 0) {
            // Don't use the webpath to avoid the transfer through server.
            $filepath = $this->_mediaFilePath($media, $derivativeType);
            if (file_exists($filepath)) {
                return $filepath;
            }
            // Use the web url when an external storage is used. No check can be
            // done.
            // TODO Load locally the external path? It will be done later.
            else {
                $filepath = $media->thumbnailUrl($derivativeType);
                return $filepath;
            }
        }
    }

    /**
     * Helper to get width and height of an image.
     *
     * @param string $filepath This should be an image (no check here).
     * @return array Associative array of width and height of the image file.
     * If the file is not an image, the width and the height will be null.
     * @see UniversalViewer_View_Helper_IiifInfo::_getWidthAndHeight()
     */
    protected function _getWidthAndHeight($filepath)
    {
        if (file_exists($filepath)) {
            list($width, $height, $type, $attr) = getimagesize($filepath);
            return array(
                'width' => $width,
                'height' => $height,
            );
        }

        return array(
            'width' => null,
            'height' => null,
        );
    }
}
