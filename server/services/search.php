<?php

require dirname(__FILE__) . '/../classes/headstart/preprocessing/calculation/RCalculation.php';
require dirname(__FILE__) . '/../classes/headstart/preprocessing/naming/KeywordNaming.php';
require dirname(__FILE__) . '/../classes/headstart/persistence/SQLitePersistence.php';
require_once dirname(__FILE__) . '/../classes/headstart/preprocessing/Snapshot.php';
require_once dirname(__FILE__) . '/../classes/headstart/library/CommUtils.php';
require_once dirname(__FILE__) . '/../classes/headstart/library/toolkit.php';

require 'helper.php';

use headstart\library;

function packParamsJSON($params_array, $post_params) {

    if($params_array === null) {
        return null;
    }

    $output_array = array();

    foreach ($params_array as $entry) {
        $current_params = library\CommUtils::getParameter($post_params, $entry);
        $output_array[$entry] = $current_params;
    }

    return json_encode($output_array);
}

function utf8_converter($array)
{
    array_walk_recursive($array, function(&$item, $key){
        if(!mb_detect_encoding($item, 'utf-8', true)){
                $item = utf8_encode($item);
        }
    });

    return $array;
}

function cleanQuery($dirty_query, $transform_query_tolowercase) {
    $query = strip_tags($dirty_query);
    $query = trim($query);

    if ($transform_query_tolowercase) {
        $query = strtolower($query);
    }

    $query = addslashes($query);

    return $query;
}

function search($repository, $dirty_query, $post_params, $param_types, $keyword_separator, $taxonomy_separator, $transform_query_tolowercase = true
        , $retrieve_cached_map = true, $params_for_id = null, $num_labels = 3, $id = "area_uri", $subjects = "subject", $precomputed_id = null, $do_clean_query = true
        , $backend = "legacy") {
    $INI_DIR = dirname(__FILE__) . "/../preprocessing/conf/";
    $ini_array = library\Toolkit::loadIni($INI_DIR);

    $query = ($do_clean_query === true)
                ?(cleanQuery($dirty_query, $transform_query_tolowercase))
                :($dirty_query);

    $persistence = new \headstart\persistence\SQLitePersistence($ini_array["connection"]["sqlite_db"]);

    $settings = $ini_array["general"];

    $params_json = packParamsJSON($param_types, $post_params);

    $params_for_id_creation = ($params_for_id === null)?($params_json):(packParamsJSON($params_for_id, $post_params));

    $unique_id = ($precomputed_id === null)?($persistence->createID(array($query, $params_for_id_creation))):($precomputed_id);

    if($retrieve_cached_map) {
        $last_version = $persistence->getLastVersion($unique_id, false);

        if ($last_version != null && $last_version != "null" && $last_version != false) {
            echo json_encode(array("query" => $query, "id" => $unique_id, "status" => "success"));
            return;
        }
    }

    $params_file = tmpfile();
    $params_meta = stream_get_meta_data($params_file);
    $params_filename = $params_meta["uri"];
    fwrite($params_file, $params_json);

    $WORKING_DIR = $ini_array["general"]["preprocessing_dir"] . $ini_array["output"]["output_dir"];

    if ($backend === "api") {
      $url = $ini_array["general"]["api_url"] . $repository . "/search";
      $payload = json_encode($post_params);
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $output_json = curl_exec($ch);
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($httpcode != 200) {
        $output_json = NULL;
      }
    } else {
      $calculation = new \headstart\preprocessing\calculation\RCalculation($ini_array);
      $output = $calculation->performCalculationAndReturnOutputAsJSON($WORKING_DIR, $query, $params_filename, $repository);

      $output_json = end($output);
      $output_json = mb_convert_encoding($output_json, "UTF-8");
    }

    if (!library\Toolkit::isJSON($output_json) || $output_json == "null" || $output_json == null) {

        echo json_encode(array("status" => "error"));;
        return;
    }

    $result = json_decode($output_json, true);

    if (isset($result["status"]) && $result["status"] === "error") {
        return json_encode($result);
    }
    if (isset($result["status"]) && $result["status"] === "No update required") {
      return json_encode(array("query" => $query, "id" => $unique_id, "status" => "success"));
    }

    $input_json = json_encode(utf8_converter($result));
    $input_json = preg_replace("/\<U\+(.*?)>/", "&#x$1;", $input_json);

    $vis_title = $repository;

    $exists = $persistence->existsVisualization($unique_id);

    if (!$exists) {
        $persistence->createVisualization($unique_id, $vis_title, $input_json, $query, $dirty_query, $params_json);
    } else {
        $persistence->writeRevision($unique_id, $input_json);
    }

    $repo_mapping = array("plos" => "PLOS"
                            , "pubmed" => "PubMed"
                            , "doaj" => "DOAJ"
                            , "base" => "BASE"
                            , "openaire" => "OpenAire"
                            , "linkedcat" => "LinkedCat"
                            , "linkedcat_authorview" => "LinkedCat"
                            , "linkedcat_browseview" => "LinkedCat"
                            , "triple" => "TRIPLE");

    if(!isset($ini_array["snapshot"]["snapshot_enabled"]) || $ini_array["snapshot"]["snapshot_enabled"] > 0) {
        if (isset($post_params["vis_type"]) && $post_params["vis_type"] == "timeline") {
          $vis_type = "timeline";
        } else {
          $vis_type = "overview";
        }
        $snapshot = new \headstart\preprocessing\Snapshot($ini_array, $query, $unique_id, $repository, $repo_mapping[$repository], $vis_type);
        $snapshot->takeSnapshot();
    }

    return json_encode(array("query" => $query, "id" => $unique_id, "status" => "success"));
}
