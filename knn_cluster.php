#!/usr/bin/php
<?php
/**
 * KNNG connected component based clustering.
 * Most of this file is to do with parsing CLI options, and writing results to file.
 */

version_compare(PHP_VERSION, "5.3", ">=") or die("This script relies on features from PHP 5.3 and will fail with current version '".PHP_VERSION."'\n");
// Having a bad TZ is not E_WARNING material...
date_default_timezone_set(@date_default_timezone_get());
// Dont know why but sometimes PHP 5.3 is not printing a terminating newline here so:
ini_set("error_append_string", "\n");
ini_set("memory_limit", -1);
ini_set("display_errors", true);
ini_set("log_errors", true);
ini_set("error_log", "error.log");

#ini_set("xdebug.profiler_enable", 1);
#ini_set("xdebug.profiler_output_dir",".");
#ini_set("xdebug.profiler_output_name", "");

require_once('include/set_include_path.php');
require_once('lib_knn_cluster.php');
require_once('ec.php');
require_once('cli.php');
require_once('str.php');
require_once('KNNGraph.php');
require_once('NTreeClustering.php');
require_once('AbstractKNNClustering.php');
require_once('AbstractKNNCluster.php');
require_once('KNNClusteringVision.php');
require_once('KNNClusteringStats.php');

/** The location where clustering types - dynamically loaded. */
define('CLUSTERINGS_DIR', dirname(__FILE__).'/include/clusterings');
/** prefix to clustering modules. There is no default type. */
define('CLUSTERINGS_NAME', 'KNNClustering');
/** The location of vector types - dynamically loaded. */
define('VECTOR_DIR', dirname(__FILE__).'/include/vectors');
/** prefix to vector modules. */
define('VECTOR_NAME', 'Vector');
define('DEFAULT_VECTOR_NAME', 'Vector');
/** default file name of settings file, taken relative to the location of the 'file' option. */
define('DEFAULT_SETTINGS_FILE', 'knn_cluster_settings.php');
define('DEFAULT_K_MAX', 40);
define('USE_NTREE_CLUSTERING', true);

// Using ec.php dont set an exception handler use shutdown function. Must come after ec.php.
register_shutdown_function("handle_errors");

function handle_errors()
{
  global $error_get_last;
  if(isset($error_get_last) && ($error_get_last['type'] &~ EC_CONTINUE))
  {
    print "Error: ".$error_get_last['message']."\n";
  }
}

ClusterKNN::go($argc, $argv);

/**
 * Parse options, parse options to modules, build output file and dirnames.
 * @todo Convert vision and stats to objects, and modify this accordingly.
 * @todo methods to tell what vectors, clusterings, vision, stats options are available.
 */
class ClusterKNN
{
  /** Config. Most settable from the CLI. */
  private static $options_boolean = array("true" => array("true", "yes", "on"), "false" => array("false", "no", "off"));
  private static $options = array(
  'k_max'    =>  null,        //int
  'k_sweep'  => null,        //int
  'file'    => null,        //string
  'cache'    => null,        //bool
  'cluster'  => null,        //string
  'vector'    => null,        //string
  'vision'    => null,        //string
  'stats'    => null,        //bool
  'stdout'    => false,      //bool
  'output_dir'=> null,        //string
  'settings'  => null,        //string
  'cluster_options' => array(),    //array
  'vector_options' => array(),    //array
  'vision_options' => array(),    //array
  'stats_options' => array());
  private static $module_prefix_keys = array("cluster" => "co_", "vision" => "vo_", "vector" => "xo_", "stats" => "so_",);
  /** CLI option parser options. */
  private static $cli_options_options = array(
  'k_max'    =>  array('long' => 'km'),    //int
  'k_sweep'  => array('long' => 'ks'),    //int
  'file'    => array('short' => 'f'),    //file
  'cache'    => array(),                //bool
  'cluster'  => array('short' => 'c'),    //string
  'vector'    => array(),                //string
  'vision'    => array('short' => 'v'),    //string
  'stats'    => array('short' => 's'),    //string
  'stdout'    => array(),                //bool
  'output_dir'=> array('short' => 'O'),    //string
  'settings'  => array('short' => 'z'),    //string
  'help'    => array('short' => 'h'));    //bool
  private static $help = array(
  'k_max'    =>  'The k value the knn graph will be built at.',
  'k_sweep'  => 'If and then what k value to start sweep at if k_sweep set. k_max sets upperbound on sweep.',
  'file'    => 'Data file containing data points. Can also be a cache file ending in ".cache"',
  'cache'    => 'The knn graph will be cached to the input file\'s directory with extension ".cache". The graph is built at k_max',
  'cluster'  => 'How the data should be clustered. Theres is a number of options.',
  'vector'    => 'The type of vector to use, e.g. looped, lp0.',
  'vision'    => 'If and then how the data should be visualized. There is a number of options.',
  'stats'    => 'If and then how the data should be summarized. There is a number of options.',
  'stdout'    => 'Whether to output stats to stdout. Only if stats option set.',
  'output_dir'=> 'Directory to output cluster and script files to.',
  'settings'  => 'Specify php file containing a assoc array of visualization settings, or if no value look in same dir as data. Unsafe hack.',
  'help'    => 'Show this help message.');
  /** The KNNGraph passed to the clustering */
  private static $knn_graph;
  /** The clustering of the graph */
  private static $clustering;
  /** The class of the clustering */
  private static $clustering_class;
  /** The class of the vector passed to the KNN graph on init */
  private static $vector_class;
  /** KNNGraph option */
  private static $assume_labeled;
  /** KNNGraph option */
  private static $handle_duplicates;
  private static $output_dir;
  private static $vision_output_dirname;
  private static $stats_output_dirname;
  private static $vision_output_filename;
  private static $stats_output_filename;
  private static $name;
  private static $output_ksweep;

  public static function go($argc, $argv)
  {
    // Sets all the above to thier required values.
    // Values are used directly from the options array if type 1 external parameter.
    self::read_options($argc, $argv);
    self::$output_ksweep = fopen("PHP://stderr", "w");

    // The most confusing part about all this is the output filenames! Decision was made to handle them here rather than in modules that need them,
    // such that we have control here rather than having to change methods in modules.
    // output file formats:
    //   <output dir>/<name>"_"<clustering type>"_"("vision"|"stats")/<name><full options except k>/<type specific start><name><full options with k><type specific end>
    //   <full options except k> -> <clustering type string except k>"_"<vector type string>"_"<vision type string>
    //   <full options with k> -> <clustering type string including k>"_"<vector type string>"_"<vision type string>
    // I.e that clustering specification, vector type specification and how the data is being visualized all must be present in file names.
    // Modules take an output dirname and filename. Modules may add <type specific start> and <type specific end> to filename, to files created in dirname.

    if(self::$options['cluster'])
    {
      self::make_output_dirnames();

      if(isset(self::$options['k_sweep']))
      {
        self::k_sweep();
      }
      else
      {
        self::output();
      }
    }
    if(self::$options['cache'])
    {
      self::cache_KNNGraph();
    }
    print "Done!\n";
  }

  /**
   * Iteratively change the k value then recluster on a clustering that supports a k option.
   * Added hack to output just k_sweep to stderr in case just want that.
   */
  private static function k_sweep()
  {
    print "K sweeping\n";
    $k = self::$options['k_sweep'];
    do
    {
      print "$k of ".self::$clustering->get_k_max()."\n";
      self::$clustering->set_option_k($k);
      self::$clustering->cluster();
      self::output();
      $k++;
    }
    while($k <= self::$clustering->get_k_max());
  }

  /**
   * Write required output type to output dirs.
   */
  private static function output()
  {
    //print "Inside ".__METHOD__." object type = ".get_class($this)."\n";
    // Set and (vision|stats)_output_filename
    self::make_output_filenames();

    if(self::$options['vision'])
    {
      print "Vision output to ".self::$vision_output_dirname."/".self::$vision_output_filename."\n";
      KNNClusteringVision::visualize_clustering(self::$clustering, self::$vision_output_dirname, self::$vision_output_filename, self::$options['vision'], self::$options['vision_options']);
    }
    if(self::$options['stats'])
    {
      print "Stats output to ".self::$stats_output_dirname."/".self::$stats_output_filename."\n";
      KNNClusteringStats::stats_clustering(self::$clustering, self::$stats_output_dirname, self::$stats_output_filename, self::$options['stdout'], self::$options['stats_options']);
    }
  }

  // get option from external sources
  // check file
  // set output directory
  // do pre check on clustering class and clustering options
  // check and set other fields
  // do pre check on vision and vision options by loading them
  // create graph by loading pre existing or building new using the file parameter as needed.
  // create clustering using created graph.
  function read_options($argc, $argv)
  {
    // Parse the options into interanl assoc array format.
    // Ideally do no prepro on options.
    // Uses settings, file.
    self::parse_options($argc, $argv);

    //Fully qualify file and output_dir. Set output_dir field.
    self::$options['file'] = realpath(self::$options['file']);
    if(self::$options['output_dir'] == null)
    {
      self::$output_dir = dirname(self::$options['file']);
    }
    elseif(is_dir(self::$options['output_dir']))
    {
      self::$output_dir = self::$options['output_dir'];
    }
    else
    {
      trigger_error("Output dir '".self::$options['output_dir']."' DNE", E_USER_ERROR);
    }

    // If doing clustering, load clustering class and check it supports any clustering options we have found.
    // So that you dont have to wait until after graph built to find out option is invalid.
    // Object will be constructed after graph if 'cluster' set.
    // Also sets up some other stuff needed if 'cluster' set.
    if(self::$options['cluster'])
    {
      print "Found 'cluster' option loading specified clusterer.\n";
      self::load_clustering_class();
    }
    else
    {
      print "No 'cluster' option set. Will not cluster graph.\n";
    }

    // Set name field.
    $file_parts = explode(".", basename(self::$options['file']));
    self::$name = $file_parts[0];

    // Set knn_graph, dim, k_max fields according the cached graphs values, uses vector and cache.
    // If ".cache" unserialize the cache.
    // unless k_max is set in which case update cached graphs k_max.
    // After this option k_max is set to graph's k_max regardless of who sets who, so it can be used as such.
    if($file_parts[ sizeof($file_parts) - 1 ] == "cache")
    {
      self::load_KNNGraph_from_cache();
    }
    // Else create a new graph from data file provided. Vector type only applies if reading.
    // Currently if cached you dont get told what the vector type options were on the build but says in file name anyway.
    else
    {
      self::load_KNNGraph_from_file();
    }
    print "\nGraph loaded:\n";
    self::print_array_rec(self::$knn_graph->get_options(), "\t");

    // Cluster here if cluster option was set.
    // Options were already tested.
    if(self::$options['cluster'])
    {
      print "Clustering graph.\n";
      self::$clustering = new self::$clustering_class(self::$knn_graph, self::$options['cluster_options']);
       print "\nClustering done. Options are:\n";
      self::print_array_rec(self::$clustering->get_options(), "\t");
    }
  }

  /**
   * Load clustering class and check options.
   */
  private static function load_clustering_class()
  {
    if(defined("USE_NTREE_CLUSTERING"))
    {
      self::$clustering_class = CLUSTERINGS_NAME."NTree".strtoupper(self::$options['cluster']);
    }
    else
    {
      self::$clustering_class = CLUSTERINGS_NAME.strtoupper(self::$options['cluster']);
    }
    $clustering_class_file = CLUSTERINGS_DIR."/".self::$clustering_class.".php";

    try
    {
      //Dont use require, triggers E_ERROR.
      include_once($clustering_class_file);
    }
    catch(Exception $e)
    {
      trigger_error("Cannot load vector type '".self::$options['cluster']."' from file '".$clustering_class_file."'", E_USER_ERROR);
    }
    self::check_clustering_options();
  }

  /**
   * Check option provided to dynamically loaded clustering class.
   * Since building graph may take a long time, then clustering fails because of bad option.
   */
  private static function check_clustering_options()
  {
    //I can ref a class with a string but I cant use 2x "::" why?
    // Pre check module supports option - relies on has_option() static method.
    $clustering_class = self::$clustering_class;
    foreach(self::$options['cluster_options'] as $ckey => $cval)
    {

      if(! $clustering_class::has_option($ckey))
      {
        trigger_error("Clustering self::$clustering_class has no option '$ckey' cannot continue", E_USER_ERROR);
      }
    }

    if(isset(self::$options['k_sweep']) && (! in_array("KSweepableClustering", class_implements(self::$clustering_class))))
    {
      trigger_error("The clustering type '".self::$options['cluster']."' does not support k_sweep", E_USER_ERROR);
    }
  }

  /**
   * Load a graph from serialized.
   * If any settings that relate to graph build are set then error coz they are already set previously.
   */
  private static function load_KNNGraph_from_cache()
  {
    print "Loading graph from cache.\n";
    if(! empty($options['vector_options']))
    {
      trigger_error("Loading previous graph from cache cannot set vector type options", E_USER_ERROR);
    }
    self::$knn_graph = unserialize(file_get_contents(self::$options['file']));
    // Increase k if needed.
    if(self::$knn_graph->get_k_max() < self::$options['k_max'])
    {
      print "Graph k_max increase needed. Increasing k_max:\n";
      self::$knn_graph->set_k_max(self::$options['k_max']);
    }
  }

  /**
   * Build graph newly from data file.
   * Takes a while to build graph.
   */
  private static function load_KNNGraph_from_file()
  {
    print "Loading vector.\n";
    self::$vector_class = DEFAULT_VECTOR_NAME;
    $vectors = array();

    // Import the vector class to use - allows overide of distance measure etc.
    if(isset(self::$options['vector']))
    {
      self::$vector_class = VECTOR_NAME.strtoupper(self::$options['vector']);
      $vector_class_file = VECTOR_DIR."/".self::$vector_class.".php";
      try
      {
        //Dont use require, triggers E_ERROR.
        include_once($vector_class_file);
      }
      catch(Exception $e)
      {
        trigger_error("Cannot load vector type '".self::$options['vector']."' file '".$vector_class_file."'", E_USER_ERROR);
      }
    }

    // Build the graph from vector just loaded.
    print "Using vector type '".self::$vector_class."'\n";
    print "Building graph.\n";
    self::$knn_graph = new KNNGraph(self::$options['file'], self::$options['k_max'], self::$handle_duplicates,  self::$assume_labeled, self::$vector_class, self::$options['vector_options']);
  }

  /**
   * Serialize KNNGraph.
   * Simple but relatively large file.
   */
  public static function cache_KNNGraph()
  {
    $vector_type_part_string = self::make_vector_type_string();
    $filename = self::$output_dir."/".self::$name.$vector_type_part_string.".cache";
    print "Caching to $filename\n";
    file_put_contents($filename, serialize(self::$knn_graph));
  }

  /**
   * Merge command line and other options into interal format.
   * Parse cli option
   * Merge options from settings file if provided
   * Collect options for known modules.
   * Trigger error if unknown options found.
   */
  function parse_options($argc, $argv)
  {
    $module_options = array();
    $cli_options = array();

    $cli_options = simple_parse_args_sane($argv, self::$cli_options_options );

    if(isset($cli_options['valids']['help']))
    {
      self::help(self::$cli_options_options, self::$help);
      exit();
    }

    print "Found cli options:\n";
    self::print_array_rec($cli_options['valids'], "\t");

    // Only clobber old with new if new is not null.
    self::$options = self::merge_valid_options(self::$options, $cli_options['valids']);

    // Set the file option.
    // This means you cant put the option 'file' in the settings file
    // if you dont specify an explicit location for the settings file because in that case it is relative to 'file'.
    // Exception to teh rule that we dont want to do any preprocessing on these options yet.
    if(! self::$options['file'])
    {
      trigger_error("Must provide a data file", E_USER_ERROR);
    }

    $not_a_file = self::$options['file'];
    self::$options['file'] = realpath(self::$options['file']);

    if(! is_file(self::$options['file']))
    {
      trigger_error("Data file '".$not_a_file."' is not a file", E_USER_ERROR);
    }

    // Merge CLI over Include Hack over Default.
    // First do include hack supposed to clobber options with your data set specific options. unsafe.
    if(self::$options['settings'])
    {
      print "Merging user provided php file \$options over internal \$options - dodge.\n";
      if(self::$options['settings'] === true)
      {
        self::$options['settings'] = dirname(self::$options['file'])."/".DEFAULT_SETTINGS_FILE;
      }
      if(! is_file(self::$options['settings']))
      {
        trigger_error("Provided setttings file '". self::$options['settings']."' is not a file", E_USER_ERROR);
      }
      self::$options = self::merge_external_options(self::$options['settings'], self::$options);
    }

    // Collect the options for modules - module options have a prefix key.
    // E.g. 'co_' for clustering module option.
    foreach(self::$module_prefix_keys as $m_name => $m_key)
    {
      // Must set $m_name_"options" to an empty array.
      $module_options[$m_name] = array();
      self::$options[$m_name."_options"] = array();

      foreach($cli_options['invalids'] as $key => $val)
      {
        if(substr($key, 0, 3) == $m_key)
        {
          $module_option_name = substr($key, 3);
          self::$options[$m_name."_options"][$module_option_name] = $val;
          unset($cli_options['invalids'][$key]);
        }
      }
      print "Found $m_name options:\n";
      if(isset(self::$options[$m_name."_options"]))
      {
        self::print_array_rec(self::$options[$m_name."_options"], "\t");
      }
    }

    // Error if unknown options provided.
    if(! empty($cli_options['invalids']))
    {
      trigger_error("Invalid option(s) '".implode(", ", array_keys($cli_options['invalids']))."' given", E_USER_ERROR);
    }
  }

  /**
   * Merge two array but only clobber $base with $over if $over non null.
   */
  private static function merge_valid_options(Array $base, Array $over)
  {
    foreach($over as $k => $v)
    {
      if(isset($v))
      {
        $base[$k] = $v;
      }
    }
    return $base;
  }

  /**
   * Convenience function to print array with array in nicely.
   */
  private static function print_array_rec(Array $array, $indent = "")
  {
    foreach($array as $k => $v)
    {
      if(is_array($v))
      {
        print $indent.$k.":\n";
        self::print_array_rec($v, $indent."\t");
      }
      else
      {
        print $indent.$k." => $v\n";
      }
    }
  }

  /**
   * Make required output dirnames, and create the dirs.
   * Ignore the K option in the names.
   * The way file hierarchy is organised is a design descision.
   * Output to file is largely for convenience and it is a large convenience when dealing with many types/settings of clusterings.
   */
  public static function make_output_dirnames()
  {
    // Build clustering output dirnames.
    $clustering_name = self::$clustering->get_type();
    $clustering_type_part_string = self::make_clustering_type_string(array("k"));
    $vector_type_part_string = self::make_vector_type_string();
    $vision_type_part_string = self::make_vision_type_string();
    $stats_type_part_string = "";
    self::$vision_output_dirname = self::$output_dir."/".self::$name."_".$clustering_name."_vision/";
    self::$stats_output_dirname = self::$output_dir."/".self::$name."_".$clustering_name."_stats/";
    self::$vision_output_dirname .= $clustering_type_part_string;
    self::$stats_output_dirname .= $clustering_type_part_string;
    if($vector_type_part_string)
    {
      self::$vision_output_dirname .= "_".$vector_type_part_string;
      self::$stats_output_dirname .= "_".$vector_type_part_string;
    }
    if($vision_type_part_string)
    {
      self::$vision_output_dirname .= "_".$vision_type_part_string;
    }
    if($stats_type_part_string)
    {
      //nil
    }

    // Setup fully qualified output dirs for clustering. Two levels deep, must be created with -p option.
    if(self::$options['vision'])
    {
      print "vision output to '".self::$vision_output_dirname."'\n";
      mkdir2(self::$vision_output_dirname, true, true); //true for force!
    }
    if(self::$options['stats'])
    {
      print "stats output to '".self::$stats_output_dirname."'\n";
      mkdir2(self::$stats_output_dirname, true, true);
    }
  }

  /**
   * Build both vision and stats output file names.
   */
  public static function make_output_filenames()
  {
    // Build clustering output filename.
    $clustering_type_part_string = self::make_clustering_type_string();
    $vector_type_part_string = self::make_vector_type_string();
    $vision_type_part_string = self::make_vision_type_string();
    $stats_type_part_string = "";
    self::$vision_output_filename = $clustering_type_part_string;
    self::$stats_output_filename = $clustering_type_part_string;
    if($vector_type_part_string)
    {
      self::$vision_output_filename .= "_".$vector_type_part_string;
      self::$stats_output_filename .= "_".$vector_type_part_string;
    }
    if($vision_type_part_string)
    {
      self::$vision_output_filename .= "_".$vision_type_part_string;
    }
    if($stats_type_part_string)
    {
      //nil
    }
  }

  /**
   * Make a cluster type string based on the options from the clustering not passed in,
   * since clustering may have other default options.
   * Format: <field>{_<field>}|""
   */
  function make_clustering_type_string(Array $ignore_options = array())
  {
    $str = "";
    if(self::$clustering);
    {
      $str = self::$clustering->get_type();
      $options_str = self::make_options_string(self::$clustering->get_options(), $ignore_options);
      if($options_str)
      {
        $str .= $options_str;
      }
    }
    return $str;
  }

  /**
   * Make a vector type string from the options passed in.
   * Uses the vector_options array since we have no instantiation of a vector.
   * Format: <field>{_<field>}| ""
   */
  private static function make_vector_type_string(Array $ignore_options = array())
  {
    $str = "";
    if(self::$options['vector']);
    {
      $str = self::$options['vector'];
      $options_str = self::make_options_string(self::$options['vector_options'], $ignore_options);
      if($options_str)
      {
        $str .= $options_str;
      }
    }
    return $str;
  }

  /**
   * Make a string describing the type of vision output.
   * This is enough to id the vision for now.
   * Format: <field>{_<field>}|""
   */
  private static function make_vision_type_string()
  {
    $str = "";
    if(self::$options['vision'])
    {
      $str = self::$options['vision'];
    }
    return $str;
  }

  /**
   * print all entries in an array in a consistent manner.
   */
  function make_options_string(Array $in_options, Array $ignore_options = array())
  {
    // Hack to fix k print.
    // K is an option of the particular cluster not the build.
    $str = "";
    foreach($in_options as $ckey => $cval)
    {
      if(! in_array($ckey, $ignore_options))
      {
        if(is_array($cval))
        {
          $str .= self::make_options_string($cval, $ignore_options);
        }
        else
        {
          $ckey = shorten_string($ckey);
          //What ever the number is we want to pad all with max num digits zeros so they are listed in order.
          //Only prob what the max - 3 should be fine. and one decimal place id float.
          if(is_numeric($cval))
          {
            $cval = (string) $cval;

            if(ctype_digit($cval))
            {
              $str .= sprintf("_%s%03d", $ckey, $cval);
            }
            //Assume float.
            else
            {
              $str .= sprintf("_%s%03.2f", $ckey, $cval);
            }
          }
          elseif(is_bool($cval))
          {
            //Convention to show true only jsut suites me right now - shit.
            if($cval)
            {
              $str .= "_$ckey";
            }
          }
          else
          {
            $str .= "_$ckey$cval";
          }
        }
      }
    }
    return $str;
  }

  /**
   *
   */
  private static function make_visualization_type_string()
  {
    $str = "";
    $str .= "_".self::$options['vision'];
    if((isset( $options['vision_options']['visual_k']) && isset(self::$options['clustering_options']['k'])) && ($options['vision_options']['visual_k'] != self::$options['clustering_options']['k']))
    {
      $str .= sprintf("_k%03d", $options['vision_options']['visual_k']);
    }
    return $str;
  }

  /**
   * Print help.
   * options, cli_options_options, help
   */
  private static function help()
  {
    print "help:\n";
    foreach(self::$options as $opt_name => $opt_val)
    {
      $str = "--$opt_name";
      if(isset(self::$cli_options_options[$opt_name]['long']))
      {
        $str .=  "|--".self::$cli_options_options[$opt_name]['long'];
      }
      if(isset(self::$cli_options_options[$opt_name]['short']))
      {
        $str .= "|-".self::$cli_options_options[$opt_name]['short'];
      }
      if(isset(self::$help[$opt_name]))
      {
        $str .= "\n\t".self::$help[$opt_name];
      }
      $str .= "\n";
      print $str;
    }
    print "Note: relies on PHP >= 5.3 and gnuplot >= 4.4 for plots. No checking done or warnings given.\n";
  }

  /**
   *
   */
  private static function merge_external_options()
  {
    require_once($ext_file);
    if(! isset(self::$options))
    {
      throw new Exception("External options file has bad format");
    }
    $options = array_merge(self::$options, self::$options);
  }
}
?>
