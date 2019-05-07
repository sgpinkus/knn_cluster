<?php
/**
 * Various functions for getting responses / asking questions on the CLI.
 */


/**
 * The UNIX pseudo convention for parsing cli args is stupid. name === -n -a -m -e ?! Also it's not
 * obvious from a stand alone args string whether an option is associated with a given arg. You need
 * an additional schema.
 * This implementation is ~POSIX compatible not the aforemented legacy crap. It supports short and
 * long args, and groups of shorts but any argument to a option *must* be set as follows:
 *
 *  <options> -> {<opt>\s+}*
 *  <opt> -> <opt_name>[=<opt_arg>]
 *  <opt_name> -> (-\w|--\w+)
 *  <opt_arg> -> \w*|".*"
 * E.g:
 *  ./prog -x -vfi --arg=value --arg2="a b c" - -- other shit -f
 *
 * This relies only UNIX shell word boundary splitting WS inside "" is not a word boundary and the "" are striped away by UNIX shell.
 *
 *   - Any non option args are lumped into an array and returned with options.
 *   - Does not support checking for mandatory and optional args - it does not need to know in advance whether an option is opt/mand (!) unlike getopt.
 *   - Does support checking for mandatory in second phase after using this.
 *
 * @param $argv Array the UNIX argv array.
 * @return An assoc array with keys -> opt names, values -> any associated arg. with exception entry "non_option_args" is special key with array value.
 * any opt without a arg set to logical true, while "<opt>=" set to "", which is === '<opt>=""'
 */
function _simple_parse_args_sane(Array $argv)
{
  $options = array();
  $non_option_args = array();

  foreach($argv as $i => $opt)
  {

    // '-' is an allowed option as long as
    if($opt == "-")
    {
      $options['-'] = true;
    }

    //special
    else if(preg_match("/^-=(.*)$/", $opt, $matches))
    {
      $options['-'] = $matches[1];
    }

    //parse short options
    else if(preg_match("/^-([^-=][^=]*)(=?)(.*)$/", $opt, $matches))
    {
      $short_opts = $matches[1];
      for($j = 0; $j < strlen($short_opts); $j++)
      {
        $short_opt = substr($short_opts, $j, 1);
        $options[$short_opt] = true;
      }
      if($matches[2])
      {
        $options[$short_opt] = $matches[3];
      }
    }

    //parse long option
    else if(preg_match("/^--([^=]+)(=?)(.*)$/", $argv[$i], $matches))
    {
      $long_opt = $matches[1];
      $options[$long_opt] = true;
      if($matches[2])
      {
        $options[$long_opt]  = $matches[3];
      }
    }

    //special arg "--" means stop processing; rest is a non option arg regardless of form.
    else if($argv[$i] == "--")
    {
      if(isset($argv[$i+1]))
      {
        $non_option_args = array_merge($non_option_args,  array_slice($argv, $i+1));
      }
      break;
    }

    //the arg must be a non option arg.
    else
    {
      array_push($non_option_args, $argv[$i]);
    }
  }
    $options['non_option_args'] = $non_option_args;
    return  $options;
}

/**
 * Supports equivalence of long and short, and collecting valid v invalid args. Normally youd die
 * if there is an arg you dont understand. Here you can check for non empty invalid args in returned
 * value and do what you like from there.
 *
 * $valid_options is an array of arrays with form:
 *  array(
 *    'long' => <longname>
 *    'short' => <shortname>
 *    )
 * Only one of long|short need be defined. You can have other stuff in the array - like help string ...
 *
 * Return value is an array containing two arrays with form:
 *   array(
 *     'valid' => array(<cardinal> => <value found> , ...)
 *     'invalid' => array(<name found> => <value found> , ...)
 *     'args'
 *     )
 *
 * Any option found with no option arg is set to true. No check done for conflicting args.
 * @param argv Array argv like array of cli options.
 * @param valid_option_spec Array describing the set of valid cli options.
 * @param fill boolean sets all non found entries in $valid_options to null if not found, rather than not at all.
 *    so you dont have to use isset() if using returned value directly.
 * @see simple_parse_args_sane_2().
 */
function simple_parse_args_sane(Array $argv, Array $valid_options, $fill = false)
{
  $valids = array();
  $invalids = array();
  $args = array();

  // Pre fill valids with null.
  if($fill)
  {
    $valids = array_fill_keys(array_keys($valid_options), null);
  }

  $options = _simple_parse_args_sane($argv);

  $args = $options['non_option_args'];
  unset($options['non_option_args']);

  foreach($options as $option_name => $option_arg)
  {
    $found_valid = false;
    foreach($valid_options as $valid_option_name => $valid_option_spec)
    {
      $short_names = array();
      $long_names = array();
      if(isset($valid_option_spec['short']))
      {
        $short_names = preg_split("/ +/", $valid_option_spec['short']);
      }
      if(isset($valid_option_spec['long']))
      {
        $long_names = preg_split("/ +/", $valid_option_spec['long']);
      }
      if($option_name == $valid_option_name ||  in_array($option_name, $short_names) || in_array($option_name, $long_names))
      {
        $valids[$valid_option_name] = $option_arg;
        $found_valid = true;
        break;
       }
     }
    if(! $found_valid)
    {
      $invalids[$option_name] = $option_arg;
    }
   }
  return array('valids' => $valids, 'invalids' => $invalids, 'args' => $args);
}

function cli_response($question)
{}

/**
 * Ask a yes or no question.
 */
function cli_ask_yes_no($question, $loop = false)
{
  $yn = array('yes', 'y', 'no', 'n');
  $stdin = fopen("php://stdin", "r");

  print $question." (y/n): ";
  $response = strtolower(trim(fread($stdin, 1024)));

  while((! in_array($response, $yn)) && $loop)
  {
    $response = strtolower(trim(fread($stdin, 1024)));
  }

  fclose($stdin);

  if(in_array($response, array('y', 'yes')))
  {
    return true;
  }
  if(in_array($response, array('n', 'no')))
  {
    return false;
  }

  return null;
}

/**
 * Select one or a number of options from a list specified as an array.
 * @param $options an array with a numbered list of options which will be printed and user select one.
 * @param $msg and optional msg to print before printing the list of options.
 * @param $loop if not empty(), on invalid seletion this is printed and the process loops.
 * @param $multiple deprecated.
 */
function select(Array $options, $msg = "", $loop = false, $multiple = false)
{
  $stdin = fopen("php://stdin", "r");

  print $msg.":\n";

  foreach($options as $i => $o)
  {
    print "$i) $o\n";
  }

  $response = trim(fread($stdin, 1024));

  while(((! ctype_digit($response)) || ($response > (sizeof($options) -1))) && $loop)
  {
    print $loop."\n";
    print $msg.":\n";
    foreach($options as $i => $o)
    {
      print "$i) $o\n";
    }
    $response = trim(fread($stdin, 1024));
  }

  fclose($stdin);

  if(ctype_digit($response))
  {
    return $response;
  }

  return null;
}

/**
 * Let CLI user select multiple of a number of options presented on the CLI.
 * Works by checking each key against each prel regex user specifies in a comma separated list.
 * If one passes number is selected.
 * If prel regex invalic ignores.
 * @input ...
 * @return ...
 */
function select_multiple(Array $options, $msg = "", $loop = false)
{
  $response;
  $pregs = array();
  $selection = array();
  $stdin = fopen("php://stdin", "r");

  print $msg.":\n";
  print "Enter a list of comma separated Perl regexps to select the option *keys* you want to select - disjunctive.\n";

  foreach($options as $i => $o)
  {
    print "$i) $o\n";
  }

  // Read a line terminated line.
  $response = trim(fread($stdin, 1024));
  $pregs =  preg_split("/,\s*/", $response);

  foreach($options as $o_key => $o_val)
  {
    $matched = false;
    foreach($pregs as $preg)
    {
      try
      {
        $m = @preg_match("/^".$preg."$/", $o_key);
        if($m)
        {
          $matched = true;
          break;
        }
      }
      catch(ErrorException $e)
      {
        // Exp is invalid, relies on Error Exception mapping.
        print "Invalid exp.\n";
        continue;
      }
    }

    if($matched)
    {
      $selection[$o_key] = $o_val;
    }
  }

  fclose($stdin);
  return $selection;
}

/**
 * Ask user whether they are sure.
 */
function are_you_sure($f_in = null, $msg = "Are you sure", $loop = false)
{
  $yn = array('yes', 'y', 'no', 'n');

  if(! is_resource($f_in ))
  {
    $f_in = fopen("php://stdin", "r");
  }

  print $msg." (y/n)?\n";
  $response = trim(fread($f_in, 1024));

  while(! in_array($response, $yn))
  {
    print $msg." (y/n)?\n";
    $response = trim(fread($f_in, 1024));
  }

  return (in_array($response, array("y", "yes")) ? true : false);
}
