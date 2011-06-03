<?php

/**
 * Loads info from shp files and converts the data to usable WKT.
 *
 * Author: Brandon Morrison.
 *
 * Credit: Much inspiration came from the following sources.
 *   - http://www.easywms.com/easywms/?q=en/node/78
 *   - Drupal's Geo/Spatial Tools modules. (http://drupal.org/project/geo, http://drupal.org/project/spatial)
 */
 
class shpParser {
  private $shpFilePath;
  private $shpFile;
  private $headerInfo;
  private $shpData;
  
  function __construct() {
    $shpData = array();
    $headerInfo = array();
  }
  
  public function loadFile($path) {
    $this->shpFilePath = $path;
    $this->shpFile = fopen($this->shpFilePath, "rb");
    $this->loadHeaders();
    
    $shpData = $this->loadRecords();
  }
  
  public function headerInfo() {
    return $this->headerInfo;
  }
  
  public function getShapeData() {
    return $this->shpData;
  }
  
  private function geomTypes() {
    return array(
      0  => 'Null Shape',
      1  => 'Point',
      3  => 'PolyLine',
      5  => 'Polygon',
      8  => 'MultiPoint',
      11 => 'PointZ',
      13 => 'PolyLineZ',
      15 => 'PolygonZ',
      18 => 'MultiPointZ',
      21 => 'PointM',
      23 => 'PolyLineM',
      25 => 'PolygonM',
      28 => 'MultiPointM',
      31 => 'MultiPatch',
    );
  }
  
  private function geoTypeFromID($id) {
    $geomTypes = $this->geomTypes();
    
    if (isset($geomTypes[$id])) {
      return $geomTypes[$id];
    }
    
    return NULL;
  }
  
  private function loadHeaders() {
    fseek($this->shpFile, 24, SEEK_SET);
    $length = $this->loadData("N");
    fseek($this->shpFile, 32, SEEK_SET);
    $shape_type = $this->geoTypeFromID($this->loadData("V"));
    
    $bounding_box = array();
    $bounding_box["xmin"] = $this->loadData("d");
    $bounding_box["ymin"] = $this->loadData("d");
    $bounding_box["xmax"] = $this->loadData("d");
    $bounding_box["ymax"] = $this->loadData("d");
    
    $this->headerInfo = array(
      'length' => $length,
      'shapeType' => $shape_type,
      'boundingBox' => $bounding_box,
    );
  }
  
  private function loadRecords() {
    fseek($this->shpFile, 100);
    
    while(!feof($this->shpFile)) {
      $records = array();
      $record = new shpRecord($this);
      $record->load();
      $records['geom'] = $record->getData();
      if (!empty($records['geom'])) {
        $this->shpData[] = $records;
      }
    }
  }
  
  public function loadData($type) {
    $type_length = $this->loadDataLength($type);
    if ($type_length) {
      $fread_return = fread($this->shpFile, $type_length);
      if ($fread_return != '') {
        $tmp = unpack($type, $fread_return);
        return current($tmp);
      }
    }
    
    return NULL;
  }
  
  private function loadDataLength($type) {
    $lengths = array(
      'd' => 8,
      'V' => 4,
      'N' => 4,
    );
    
    if (isset($lengths[$type])) {
      return $lengths[$type];
    }
    
    return NULL;
  }
}

/**
 * shpRecord
 *  - Class for handing individual records.
 */

class shpRecord {
  private $shpParser;
  private $shapeType;
  private $shpData;
  
  function __construct($parser) {
    $this->shpParser = $parser;
    $this->shapeType = NULL;
    $this->shpData = NULL;
  }
  
  public function load() {
    $this->loadStoreHeaders();

    switch($this->shapeType) {
      case 0:
        $this->loadNullRecord();
        break;
      case 1:
        $this->loadPointRecord();
        break;
      case 3:
        $this->loadPolyLineRecord();
        break;
      case 5:
        $this->loadPolygonRecord();
        break;
      case 8:
        $this->loadMultiPointRecord();
        break;
      default:
        // $setError(sprintf("The Shape Type '%s' is not supported.", $shapeType));
        break;
    }
  }
  
  public function getData() {
    return $this->shpData;
  }
  
  private function loadStoreHeaders() {
    $this->recordNumber = $this->shpParser->loadData("N");
    $tmp = $this->shpParser->loadData("N"); //We read the length of the record
    $this->shapeType = $this->shpParser->loadData("V");
  }
  
  private function loadPoint() {
    $data = array();
    $data['x'] = $this->shpParser->loadData("d");
    $data['y'] = $this->shpParser->loadData("d");
    return $data;
  }
  
  private function loadNullRecord() {
    $this->shpData = array();
  }
  
  private function loadPolyLineRecord() {
    $this->shpData = array(
      'bbox' => array(
        'xmin' => $this->shpParser->loadData("d"),
        'ymin' => $this->shpParser->loadData("d"),
        'xmax' => $this->shpParser->loadData("d"),
        'ymax' => $this->shpParser->loadData("d"),
      ),
    );
    
    $numParts = $this->shpParser->loadData("V");
    $numPoints = $this->shpParser->loadData("V");
    
    $parts = array();
    for ($i = 0; $i < $numParts; $i++) {
      $parts[] = $this->shpParser->loadData("V");
    }
    
    $parts[] = $numPoints;
    
    $points = array();
    for ($i = 0; $i < $numPoints; $i++) {
      $points[] = $this->loadPoint();
    }
    
    if ($numParts == 1) {
      $lines = array();
      for ($i = 0; $i < $numPoints; $i++) {
        $lines[] = sprintf('%f %f', $points[$i]['x'], $points[$i]['y']);
      }
      
      $this->shpData['wkt'] = 'LINESTRING (' . implode(', ', $lines) . ')';
    }
    else {
      $geometries = array();
      for ($i = 0; $i < $numParts; $i++) {
        $my_points = array();
        for ($j = $parts[$i]; $j < $parts[$i + 1]; $j++) {
          $my_points[] = sprintf('%f %f', $points[$j]['x'], $points[$j]['y']);
        }
        $geometries[] = '(' . implode(', ', $my_points) . ')';
      }
      $this->shpData['wkt'] = 'MULTILINESTRING (' . implode(', ', $geometries) . ')';
    }
    
    $this->shpData['numGeometries'] = $numParts;
  }
  
  private function loadPolygonRecord() {
    $this->loadPolyLineRecord();
  }
  
  private function loadMultiPointRecord() {
    $this->shpData = array(
      'bbox' => array(
        'xmin' => $this->shpParser->loadData("d"),
        'ymin' => $this->shpParser->loadData("d"),
        'xmax' => $this->shpParser->loadData("d"),
        'ymax' => $this->shpParser->loadData("d"),
      ),
      'numGeometries' => $this->shpParser->loadData("d"),
      'wkt' => '',
    );
    
    $geometries = array();
    
    for ($i = 0; $i < $this->shpData['numGeometries']; $i++) {
      $point = $this->loadPoint();
      $geometries[] = sprintf('(%f %f)', $point['x'], $point['y']);
    }
    
    $this->shpData['wkt'] = 'MULTIPOINT(' . implode(', ', $geometries) . ')';
  }
  
  private function loadPointRecord() {
    $point = $this->loadPoint();
    
    $this->shpData = array(
      'bbox' => array(
        'xmin' => $point['x'],
        'ymin' => $point['y'],
        'xmax' => $point['x'],
        'ymax' => $point['y'],
      ),
      'numGeometries' => 1,
      'wkt' => sprintf('POINT(%f %f)', $point['x'], $point['y']),
    );
  }
}