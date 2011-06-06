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
  private $headerInfo = array();
  private $shpData = array();
  
  public function load($path) {
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
      $records = array(
        'geom' => $this->loadRecord(),
      );
      if (!empty($records['geom'])) {
        $this->shpData[] = $records;
      }
    }
  }
  
  /**
   * Low-level data pull.
   * @TODO: extend to enable pulling from shp files directly, or shp files in zip archives.
   */
  
  private function loadData($type) {
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
  
  // shpRecord functions.
  private function loadRecord() {
    $recordNumber = $this->loadData("N");
    $this->loadData("N"); // unnecessary data.
    $shapeType = $this->loadData("V");
    
    switch($shapeType) {
      case 0:
        return $this->loadNullRecord();
        break;
      case 1:
        return $this->loadPointRecord();
        break;
      case 3:
        return $this->loadPolyLineRecord();
        break;
      case 5:
        return $this->loadPolygonRecord();
        break;
      case 8:
        return $this->loadMultiPointRecord();
        break;
      default:
        // $setError(sprintf("The Shape Type '%s' is not supported.", $shapeType));
        break;
    }
  }
  
  private function loadPoint() {
    $data = array();
    $data['x'] = $this->loadData("d");
    $data['y'] = $this->loadData("d");
    return $data;
  }
  
  private function loadNullRecord() {
    return array();
  }
  
  private function loadPolyLineRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
    );
    
    $geometries = $this->processLineStrings();
    
    $return['numGeometries'] = $geometries['numParts'];
    if ($geometries['numParts'] > 1) {
      $return['wkt'] = 'MULTILINESTRING(' . implode(', ', $geometries['geometries']) . ')';
    }
    else {
      $return['wkt'] = 'LINESTRING(' . implode(', ', $geometries['geometries']) . ')';
    }
        
    return $return;
  }
  
  private function loadPolygonRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
    );
  
    $geometries = $this->processLineStrings();
    
    $return['numGeometries'] = $geometries['numParts'];
    if ($geometries['numParts'] > 1) {
      $return['wkt'] = 'MULTIPOLYGON(' . implode(', ', $geometries['geometries']) . ')';
    }
    else {
      $return['wkt'] = 'POLYGON(' . implode(', ', $geometries['geometries']) . ')';
    }
    
    return $return;
  }
  
  /**
   * Process function for loadPolyLineRecord and loadPolygonRecord.
   * Returns geometries array.
   */
  
  private function processLineStrings() {
    $numParts = $this->loadData("V");
    $numPoints = $this->loadData("V");
    $geometries = array();
    
    $parts = array();
    for ($i = 0; $i < $numParts; $i++) {
      $parts[] = $this->loadData("V");
    }
    
    $parts[] = $numPoints;
    
    $points = array();
    for ($i = 0; $i < $numPoints; $i++) {
      $points[] = $this->loadPoint();
    }
    
    if ($numParts == 1) {
      for ($i = 0; $i < $numPoints; $i++) {
        $geometries[] = sprintf('%f %f', $points[$i]['x'], $points[$i]['y']);
      }
      
    }
    else {
      for ($i = 0; $i < $numParts; $i++) {
        $my_points = array();
        for ($j = $parts[$i]; $j < $parts[$i + 1]; $j++) {
          $my_points[] = sprintf('%f %f', $points[$j]['x'], $points[$j]['y']);
        }
        $geometries[] = '(' . implode(', ', $my_points) . ')';
      }
    }
    
    return array(
      'numParts' => $numParts,
      'geometries' => $geometries,
    );
  }
  
  private function loadMultiPointRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
      'numGeometries' => $this->loadData("d"),
      'wkt' => '',
    );
    
    $geometries = array();
    
    for ($i = 0; $i < $this->shpData['numGeometries']; $i++) {
      $point = $this->loadPoint();
      $geometries[] = sprintf('(%f %f)', $point['x'], $point['y']);
    }
    
    $return['wkt'] = 'MULTIPOINT(' . implode(', ', $geometries) . ')';
    return $return;
  }
  
  private function loadPointRecord() {
    $point = $this->loadPoint();
    
    $return = array(
      'bbox' => array(
        'xmin' => $point['x'],
        'ymin' => $point['y'],
        'xmax' => $point['x'],
        'ymax' => $point['y'],
      ),
      'numGeometries' => 1,
      'wkt' => sprintf('POINT(%f %f)', $point['x'], $point['y']),
    );
    
    return $return;
  }
}