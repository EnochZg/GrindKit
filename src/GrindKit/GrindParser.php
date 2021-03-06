<?php
namespace GrindKit;
use GrindKit\GrindFile;
use GrindKit\GrindKit;
use GrindKit\GrindParserResult;
use Exception;


/* TODO:
 *
 * Support: file Id mapping
 *
 */

class GrindParser 
{
    public $pointer;
    public $buffer;
    public $file;

    function __construct( GrindFile $file ) 
    {
        $this->file = $file;
    }

    function readFile()
    {
        $filePath = $this->file->getRealPath();
        $this->buffer = file($filePath);
        $this->pointer = 0;
    }

    function getLine()
    {
        return $this->buffer[ $this->pointer++ ];
    }

    function getNextLine()
    {
        return @$this->buffer[ $this->pointer ];
    }

    function advanceLine()
    {
        ++$this->pointer;
    }

    function eof()
    {
        return ($this->pointer + 1) >= $this->getBufferSize();
    }

    function getBufferSize()
    {
        return count($this->buffer);
    }

    function getBuffer()
    {
        return $this->buffer;
    }


    function isPhpFunction($fn)
    {
        if( substr($fn,0,5) === 'php::' )
            return substr($fn,5);
    }

    function isMethodCall($fn)
    {
        if( preg_match('/^([^-]+)->([^-]+)/',$fn,$regs) ) {
            return array( 'class' => $regs[1], 'method' => $regs[2] );
        }
    }


    /* the valgrind parser implementation for version 1 spec
     *
     */
    function parse()
    {
        if( empty($this->buffer) )
            $this->readFile();

        $result = new GrindParserResult;
		
		// Read information into memory
        $lines = $this->getBuffer();
        $size = $this->getBufferSize();
        $lastFunction = null;
        while( ($line = $this->getLine()) ) 
        {
            $line = rtrim( $line );

            if( strlen($line) == 0 )
                continue;

            /* skip comments */
            if( strpos($line,'#') === 0 )
                continue;

            // CostPosition := "ob" | "fl" | "fi" | "fe" | "fn"
            // function invocation
            if(substr($line,0,3)==='fl=')
            {
                $filename = substr( rtrim($line),3); // function file
                $funcname = substr( rtrim($this->getLine()) , 3);
                $costline = rtrim($this->getLine());
                $costs = explode(' ',$costline);
                $funcdata = array( 
                    'filename'    => $filename,
                    'function'    => $funcname,
                    'is_method'   => $this->isMethodCall($funcname),
                    'is_php' => $this->isPhpFunction($funcname),
                );

                // with floating instructions costs
                if( count($costs) == 4 ) {
                    list($line,$selfCost,$instructions,$floating) = $costs;
                    $funcdata['line'] = (int) $line;
                    $funcdata['self_cost'] = (int) $selfCost;
                    $funcdata['inclusive_cost'] = (int) $selfCost;
                    $funcdata['instructions'] = (int) $instructions;
                    $funcdata['floating'] = (int) $floating;
                }
                // without floating instructions costs
                elseif( count($costs) == 3 ) {
                    list($line,$selfCost,$instructions) = $costs;
                    $funcdata['line'] = (int) $line;
                    $funcdata['self_cost'] = (int) $selfCost;
                    $funcdata['inclusive_cost'] = (int) $selfCost;
                    $funcdata['instructions'] = (int) $instructions;
                }
                elseif( count($costs) == 2 ) {
                    list($line,$selfCost) = $costs;
                    $funcdata['line'] = (int)$line;
                    $funcdata['inclusive_cost'] = (int) $selfCost;
                    $funcdata['self_cost'] = (int)$selfCost;
                }

                $lastFunction = $result->addCall( $funcdata );

                // $info01 = rtrim(fgets($in));
                // $info02 = rtrim(fgets($in));
            } 
            /* is a "call to function" */
            else if(substr($line,0,4)==='cfl=') 
            {
                if( ! $lastFunction )
                    throw new Exception("Can not parse call to function: Last function is empty.");
                $filename = rtrim(substr($line,4));

                // call to function function name "cfn="
                $line = $this->getLine();
                $funcname = rtrim(substr($line,4));

                $funcdata = array(
                    'function'    => $funcname,
                    'filename'    => $filename,
                    'is_method'   => $this->isMethodCall($funcname),
                    'is_php'      => $this->isPhpFunction($funcname),

                    // called from functino
                    'called_from' => $lastFunction->data['function'], // called from "last function (fn) we parsed."
                );

                $next = $this->getNextLine();

                // if calls= is specified.
                if( substr($next,0,6) === 'calls=' ) {
                    // calls=(Call Count) (Destination position)
                    list($count,$destline) = explode(' ', substr(rtrim($next),6) );
                    $funcdata['invocation_count'] = (int) $count;
                    $funcdata['destination'] = (int) $distline;
                    $this->advanceLine();

                    $next = $this->getNextLine();
                    $costline = rtrim($next);

                    // (Source position) (Inclusive cost of call)
                    $costs = explode(' ',$costline);
                    list($sourceline,$inclusivecost) = $costs;
                    $funcdata['line'] = (int) $sourceline;  // call {function} from {line}
                    $funcdata['self_cost'] = (int) $inclusivecost;

                    $lastFunction->data['inclusive_cost'] += (int) $inclusivecost;
                    $this->advanceLine();
                }
                $lastFunction->addChild( $funcdata );
            } 
            else if(strpos($line,': ')!==false){
				// Found header
				$result->headers[] = trim($line);
			}
		}
        return $result;
    }

}


