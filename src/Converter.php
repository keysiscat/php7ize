<?php

namespace Firehed\PHP7ize;

class Converter {

  private $source_file;
  private $output_file;
  private $should_echo;
  private $is_quiet = false;

  public function setIsQuiet($is_quiet) {
    $this->is_quiet = $is_quiet;
    return $this;
  }

  public function setOutputFile($output_file) {
    $this->output_file = $output_file;
    return $this;
  }

  public function setEcho($should_echo) {
    $this->should_echo = $should_echo;
    return $this;
  }

  public function setSource($source_file) {
    $this->source_file = $source_file;
    return $this;
  }

  public function convert() {
    $tokens = token_get_all(file_get_contents($this->source_file));

    foreach ($tokens as $token) {
      if ($this->near_function) {
        if ($token === '(') {
          $this->addText($token);
          $this->startParamCapture();
        }
        elseif ($this->capture_function_params) {
          $this->function_params[] = $token;
          if ($token === ')') {
            $this->endFunctionMode();
          }
        }
        else {
          // This is the actual function name, or whitespace near it
          $this->add($token);
        }
      }
      else {
        if (is_array($token)) {
          $this->handleToken($token);
        }
        else {
          $this->addText($token);
        }
      }
    } // Token loop

    // render and output
    echo ($this->output);
  }

  private function add($token) {
    if (is_array($token)) {
      $this->addText($token[1]);
    } else {
      $this->addText($token);
    }
  }

  private $capture_function_params = false;
  private function startParamCapture() {
    $this->capture_function_params = true;
  }

  private function endFunctionMode() {
    $this->addFunctionParams();
    $this->addReturnAnnotation();
    $this->near_function = false;
    $this->capture_function_params= false;
    // Done, clean up for the next function
    $this->current_return_type = '';
    $this->current_param_types = [];
    $this->function_params = [];

  }

  private function addFunctionParams() {
    $param_parts = [];
    $param_no = 0;
    foreach ($this->function_params as $tok) {
      // Loop over the tokens, break into params
      if ($tok === ',' || $tok === ')') {
        // process param
        $this->mungeParam($param_parts, $param_no);
        $this->addText($tok);
        $param_parts = [];
        $param_no++;
      } else {
        $param_parts[] = $tok;
      }
    }
  }

  private function mungeParam($parts, $number) {
    $seen_var = false;
    $has_annotation = false;
    if (isset($this->current_param_types[$number])) {
      $typehint = $this->current_param_types[$number];
    }
    else {
      $this->warn("No typehint in annotation");
      array_map(function($part) { $this->add($part); }, $parts);
      return;
    }

    foreach ($parts as $part) {
      if ($seen_var) {
        $this->add($part);
      }
      elseif (is_array($part)) {
        if ($part[0] === T_VARIABLE) {
          // figure out if we need to annotate
          if (!$has_annotation) {
            $this->addDocblockAnnotation($typehint);
          }
          $seen_var = true;
          $this->addText($part[1]);
        }
        else {
          $this->addText($part[1]);
          if ($part[0] !== T_WHITESPACE) {
            if ($typehint != $part[1]) {
              $this->warn(
                "Docblock type '%s' does not match function signature type '%s'",
                $typehint,
                $part[1]
              );
            }
            $has_annotation = true;
          }
        }

      }
      else {
        $this->addText($part);
      }
    }
  }

  private $function_params = [];
  private function handleToken(array $token) {
    list($idx, $str, $line) = $token;

    if (T_DOC_COMMENT == $idx) {
      $this->parseDocblock($str);
    }
    elseif (T_FUNCTION == $idx) {
      $this->handleFunction($str);
    }
    elseif (T_WHITESPACE == $idx) {
      $this->addText($str);
    }
    else {
      $this->addText($str);
    }
  } // handleToken

  private function addReturnAnnotation() {
    if (!$this->current_return_type) {
      return;
    }
    $return_annotation = sprintf(': %s', $this->current_return_type);
    $this->addText($return_annotation);
  }

  private $near_function = false;
  private function handleFunction($funstr) {
    $this->near_function = true;
    $this->addText($funstr);
  }

  private $current_return_type = '';
  private $current_param_types = [];
  private function parseDocblock($docblock) {
    preg_match('#@return\s*([\\\\\w]+)#', $docblock, $return_annotation);
    $this->current_return_type = $return_annotation ? $return_annotation[1] : '';

    // Escaping hell, the actual group is ([\\\w]+), meaning A-Za-z\
    preg_match_all('#@param\s*([\\\\\w]+)#', $docblock, $param_annotations);
    $this->current_param_types = $param_annotations[1];

    $this->addText($docblock);
  }

  private static $blacklisted_typehints = [
    // Reserved keywords not implemented in STH
    'mixed',
    'resource',
    'numeric',
    'object',
    // Non-reserved, but has a chance of becoming so. Preventative measure.
    // This will break compatibility if there's a legit TH to a Scalar class
    'scalar',
    'null', // Meaningless
    'false',
    'true',
  ];
  private static $coercions = [
    'integer' => 'int',
    'double' => 'float',
    'boolean' => 'bool',
  ];
  private function addDocblockAnnotation($annotation_str) {
    // We're going to make a rather stupid assumption where if there's
    // a capital letter, the script wanted a class of this name. Naming a class
    // as such is a bad idea, but we're going to assume that's what you wanted.
    if (in_array($annotation_str, self::$blacklisted_typehints)) {
      $this->warn("Skipping blacklisted annotation '%s'", $annotation_str);
      return;
    }
    if (isset(self::$coercions[$annotation_str])) {
      $annotation_str = self::$coercions[$annotation_str];
    }
    $this->addText(sprintf('%s ', $annotation_str));
  }

  private $output;
  private function addText($text) {
    $this->output .= $text;
    return $this;
  }

  /**
   * @param string sprintf-style format string
   *
   * @return void
   */
  private function warn($msg, ...$vars) {
    if ($this->is_quiet) {
      return;
    }
    $yellow = "\033[0;33m";
    $black = "\033[0m";
    $format_str = sprintf("%s%s%s%s\n",
      $yellow,
      "WARNING: ",
      $black,
      $msg);
    fwrite(STDERR, vsprintf($format_str, $vars));
  }

}
