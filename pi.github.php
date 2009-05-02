<?php

$plugin_info = array(
						'pi_name'			=> 'Github API',
						'pi_version'		=> '0.8',
						'pi_author'			=> 'Pascal Kriete',
						'pi_author_url'		=> 'http://pascalkriete.com/',
						'pi_description'	=> 'A wrapper to Github\'s API {@link http://develop.github.com/}',
						'pi_usage'			=> Github::usage()
					);
					
/**
 * Github Class
 *
 * @package			ExpressionEngine
 * @category		Plugin
 * @author			Pascal Kriete
 * @link			@todo
 *
 * Loosely built on the twitter timeline plugin by Derek Jones
 * Saved me some fsockopen / caching / localize work
 */
class Github {
	
	var $return_data;
	var $base_url		= 'http://github.com/api/v2/xml/';		// other formats are YAML or JSON
	var $cache_name		= 'github_cache';
	var $cache_expired	= FALSE;
	var $refresh		= 30;		// Period between cache refreshes, in minutes
	var $limit			= 20;		// @todo look up default github limit
	var $username		= '';
	var $prefix			= '';		// github vars are very generic, so use this to avoid conflicts (also a parameter)
	
	// Used by _parse_xml to clean up the returned array
	var $ignore = array('commit', 'repository', 'tree');
	var $nested = array('author' => array('name', 'email'), 'committer' => array('name', 'email'));

	/**
	 * Constructor
	 *
	 * @access	public
	 */
	function Github()
	{
		global $TMPL;
		
		// Parameters available to all functions
		
		if ( ! $this->username = $TMPL->fetch_param('username'))
		{
			$TMPL->log_item("Github Plugin Error: No username parameter given.");
			return '';
		}
		
		if ($refresh = $TMPL->fetch_param('refresh'))
		{
			$this->refresh = $refresh;
		}
		
		if ($prefix = $TMPL->fetch_param('prefix'))
		{
			$this->prefix = $prefix;
		}
		
		// @todo backspace param?
	}

	// --------------------------------------------------------------------
	
	/**
	 * exp:github:user
	 *
	 * Get user information
	 *
	 * @access	public
	 * @return	string
	 * @link	http://develop.github.com/p/users.html
	 */
	function user()
	{
		global $TMPL, $FNS;
		
		/*
		exp:github:user

		Parameters
			- username

		Variables
			- {name}
			- {company}
			- {location}
			- {email}
			- {blog}
			- {following_count}
			- {followers_count}
			- {public_repo_count}
			- {public_gist_count}
			
		*/

		// Fetch the data
		
		$url = 'user/show/'.$this->username;
		
		$user_data = $this->_fetch_data($url);
		
		if ( ! $user_data)
		{
			$TMPL->log_item("Github Plugin: unable to fetch user data.");
			return '';
		}
		
		// Parse the data
		
		$output = '';
		$tagdata = $TMPL->tagdata;
		
		// Conditionals
		$tagdata = $FNS->prep_conditionals($tagdata, $user_data);
		
		foreach($TMPL->var_single as $var_key => $var_val)
		{
			// the main keys are easy
			if (isset($user_data[$var_key]))
			{
				$tagdata = $TMPL->swap_var_single($var_key, $user_data[$var_key], $tagdata);	
			}
			else
			{
				$tagdata = $TMPL->swap_var_single($var_key, '', $tagdata);
			}
		}
		
		return $tagdata;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * exp:github:repository
	 *
	 * Get repository information
	 *
	 * @access	public
	 * @return	string
	 * @link	http://develop.github.com/p/repo.html
	 */
	function repository()
	{
		global $TMPL, $FNS;
		
		/*
		exp:github:repository

		Parameters
			- username
			- repository

		Variables
			- {name}
			- {description}
			- {forks}
			- {watchers}
			- {url}
			- {owner}
			- {homepage}

		*/
		
		// Fetch Parameters

		if ( ! $repository	= $TMPL->fetch_param('repository'))
		{
			$TMPL->log_item("Github Plugin: repository tag requires repository parameter.");
			return '';
		}

		// Fetch the data
		
		$url = "repos/show/{$this->username}/{$repository}";
		$repo_data = $this->_fetch_data($url);

		if ( ! $repo_data)
		{
			$TMPL->log_item("Github Plugin: unable to fetch repository data.");
			return '';
		}
		
		// Parse the data
		
		$output = '';
		$tagdata = $TMPL->tagdata;
		
		// Conditionals
		$tagdata = $FNS->prep_conditionals($tagdata, $repo_data);
		
		foreach($TMPL->var_single as $var_key => $var_val)
		{
			// the main keys are easy
			if (isset($repo_data[$var_key]))
			{
				$tagdata = $TMPL->swap_var_single($var_key, $repo_data[$var_key], $tagdata);	
			}
			else
			{
				$tagdata = $TMPL->swap_var_single($var_key, '', $tagdata);
			}
		}
		
		return $tagdata;
	}
	
	
	// --------------------------------------------------------------------
	
	/**
	 * exp:github:tree
	 *
	 * Get tree information
	 *
	 * @access	public
	 * @return	string
	 * @link	http://develop.github.com/p/object.html#trees
	 */
	function tree()
	{
		global $TMPL, $FNS;
		
		/*
		exp:github:tree

		Parameters
			- username
			- repository
			- tree
			- limit
			- show_hidden

		Variables
			- {name}
			- {sha}
			- {mode}
			- {type}
			- {count}
			- {switch="a|b"}

		*/

		// Fetch parameters
		
		$repository	= $TMPL->fetch_param('repository');
		$tree		= $TMPL->fetch_param('tree');
		$limit		= (($tmp = $TMPL->fetch_param('limit')) === FALSE) ? $this->limit : $tmp;

		if ( ! $show_hidden = $TMPL->fetch_param('show_hidden'))
		{
			$show_hidden = ($show_hidden == 'yes') ? TRUE : FALSE;
		}

		if ( ! $repository)
		{
			$TMPL->log_item("Github Plugin: tree tag requires repository parameter.");
			return '';
		}
		if ( ! $tree OR strlen($tree) != 40)
		{
			$TMPL->log_item("Github Plugin: invalid tree hash.");
			return '';
		}

		// Fetch the data
		
		$url = "tree/show/{$this->username}/{$repository}/{$tree}";

		$tree_data = $this->_fetch_data($url);

		if ( ! $tree_data)
		{
			$TMPL->log_item("Github Plugin: unable to fetch tree data.");
			return '';
		}

		// Parse the data

		$output = '';
		$count = 0;

		foreach($tree_data as $tree)
		{
			$tagdata = $TMPL->tagdata;
			
			if ($show_hidden == FALSE && isset($tree['name']) && $tree['name'][0] == '.')
			{
				continue;
			}
			
			if ($count >= $limit)
			{
				return $output;
			}
			
			// Make {count} available - +1 due to zero indexed arrays
			$tree[$this->prefix.'count'] = $count++;
			
			// Conditionals
			$tagdata = $FNS->prep_conditionals($tagdata, $tree);
			
			foreach($TMPL->var_single as $var_key => $var_val)
			{
				// Parse switch
				
				if (preg_match("/^".$this->prefix."switch\s*=.+/i", $var_key))
				{
					$sparam = $FNS->assign_parameters($var_key);
					
					$sw = '';
					
					if (isset($sparam[$this->prefix.'switch']))
					{
						$sopt = explode("|", $sparam[$this->prefix.'switch']);

						$sw = $sopt[($count-1 + count($sopt)) % count($sopt)];
					}
					
					$tagdata = $TMPL->swap_var_single($var_key, $sw, $tagdata);
				}
				
				// the main keys are easy
				if (isset($tree[$var_key]))
				{
					$tagdata = $TMPL->swap_var_single($var_key, $tree[$var_key], $tagdata);	
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($var_key, '', $tagdata);
				}
			}
			
			$output .= $tagdata;
		}
		
		return $output;
	}
	
	// --------------------------------------------------------------------

	/**
	 * exp:github:user_repos
	 *
	 * Get user repository information
	 *
	 * @access	public
	 * @return	string
	 * @link	http://develop.github.com/p/repo.html#list_all_repositories
	 */
	function user_repos()
	{
		global $TMPL, $FNS, $LOC;

		/*
		exp:github:user_repos
		
		Parameters
			- username

		Variables
			- {description}
			- {name}
			- {forks}			- fork count
			- {watchers}
			- {owner}
			- {homepage}
			- {url}
			- {count}
			- {switch="a|b"}

		*/

		// Fetch parameters
		
		$limit		= (($tmp = $TMPL->fetch_param('limit')) === FALSE) ? $this->limit : $tmp;

		// Fetch the data
		
		$url = "repos/show/{$this->username}";

		$user_repo_data = $this->_fetch_data($url);

		if ( ! $user_repo_data)
		{
			$TMPL->log_item("Github Plugin: unable to fetch user repo data.");
			return '';
		}

		// Parse the data

		$output = '';

		foreach($user_repo_data as $count => $repo)
		{
			$tagdata = $TMPL->tagdata;
			
			if ($count >= $limit)
			{
				return $output;
			}
			
			// Make {count} available - +1 due to zero indexed arrays
			$repo[$this->prefix.'count'] = $count + 1;
			
			// Conditionals
			$tagdata = $FNS->prep_conditionals($tagdata, $repo);
			
			foreach($TMPL->var_single as $var_key => $var_val)
			{
				// Parse switch
				
				if (preg_match("/^".$this->prefix."switch\s*=.+/i", $var_key))
				{
					$sparam = $FNS->assign_parameters($var_key);
					
					$sw = '';
					
					if (isset($sparam[$this->prefix.'switch']))
					{
						$sopt = explode("|", $sparam[$this->prefix.'switch']);

						$sw = $sopt[($count-1 + count($sopt)) % count($sopt)];
					}
					
					$tagdata = $TMPL->swap_var_single($var_key, $sw, $tagdata);
				}
				
				// the main keys are easy
				if (isset($repo[$var_key]))
				{
					$tagdata = $TMPL->swap_var_single($var_key, $repo[$var_key], $tagdata);	
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($var_key, '', $tagdata);
				}
			}
			
			$output .= $tagdata;
		}
		
		return $output;
	}
	
	// --------------------------------------------------------------------

	/**
	 * exp:github:commits
	 *
	 * Get commit information
	 *
	 * @access	public
	 * @return	string
	 * @link	http://develop.github.com/p/commits.html
	 */
	function commits()
	{
		global $TMPL, $FNS, $LOC;

		/*
		exp:github:commits
		
		Parameters
			- username
			- repository
			- branch		(defaults to master)
			- path			(defaults to root)
			- commit		(sha value - shows single commit)
			- limit			(number of commits to show - cannot be used with "commit")

		Variables
			- {message}
			- {author-name}
			- {author-email}
			- {committed-date}
			- {authored-date}
			- {committer-name}
			- {committer-email}:
			- {count}
			- {switch="a|b"}

		*/

		// Fetch parameters

		$repository	= $TMPL->fetch_param('repository');
		
		$branch		= (($tmp = $TMPL->fetch_param('branch')) === FALSE) ? 'master' : $tmp;
		$limit		= (($tmp = $TMPL->fetch_param('limit')) === FALSE) ? $this->limit : $tmp;
		$path		= (($tmp = $TMPL->fetch_param('path')) === FALSE) ? '' : '/'.ltrim($tmp, '/');
		$commit		= $TMPL->fetch_param('commit');
		
		if ( ! $repository)
		{
			$TMPL->log_item("Github Plugin: commit tag requires repository parameter.");
			return '';
		}
		if ($commit && strlen($commit) != 40)
		{
			$TMPL->log_item("Github Plugin: invalid commit hash.");
			return '';
		}

		// Fetch the data
		
		$url = "commits/list/{$this->username}/{$repository}/".(($commit) ? $commit : $branch.$path);

		$commit_data = $this->_fetch_data($url);

		if ( ! $commit_data)
		{
			$TMPL->log_item("Github Plugin: unable to fetch commit data.");
			return '';
		}
		elseif ($commit)
		{
			// Code below expects an array, so we'll make it happy
			$commit_data = array($commit_data);
		}

		// Parse the data

		$output = '';
		$authored_date = array();
		$committed_date = array();

		// parse date variables outside of the loop to save processing
		if (preg_match_all("/".LD.$this->prefix.'(committed-date|authored-date)'."\s+format=(\042|\047)([^\\2]*?)\\2".RD."/s", $TMPL->tagdata, $matches))
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{
				$var = str_replace('-', '_', $matches['1'][$i]);
				
				$matches['0'][$i] = str_replace(array(LD,RD), '', $matches['0'][$i]);
				${$var}[$matches['0'][$i]] = $LOC->fetch_date_params($matches['3'][$i]);
			}
		}

		foreach($commit_data as $count => $commit)
		{
			$tagdata = $TMPL->tagdata;
			
			if ($count >= $limit)
			{
				return $output;
			}
			
			// Make {count} available - +1 due to zero indexed arrays
			$commit[$this->prefix.'count'] = $count + 1;
			
			// Conditionals
			$tagdata = $FNS->prep_conditionals($tagdata, $commit);
			
			foreach($TMPL->var_single as $var_key => $var_val)
			{
				// Parse switch
				
				if (preg_match("/^".$this->prefix."switch\s*=.+/i", $var_key))
				{
					$sparam = $FNS->assign_parameters($var_key);
					
					$sw = '';
					
					if (isset($sparam[$this->prefix.'switch']))
					{
						$sopt = explode("|", $sparam[$this->prefix.'switch']);

						$sw = $sopt[($count-1 + count($sopt)) % count($sopt)];
					}
					
					$tagdata = $TMPL->swap_var_single($var_key, $sw, $tagdata);
				}
				
				// Parse authored-date
				if (isset($authored_date[$var_key]))
				{
					$date = $this->_humanize_localize($commit[$this->prefix.'authored-date']);

					foreach ($authored_date[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $LOC->convert_timestamp($dvar, $date, TRUE), $var_val);
					}

					$tagdata = $TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}
				
				// Parse committed-date
				if (isset($committed_date[$var_key]))
				{
					$date = $this->_humanize_localize($commit[$this->prefix.'committed-date']);

					foreach ($committed_date[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $LOC->convert_timestamp($dvar, $date, TRUE), $var_val);
					}

					$tagdata = $TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}

				
				// the main keys are easy
				if (isset($commit[$var_key]))
				{
					$tagdata = $TMPL->swap_var_single($var_key, $commit[$var_key], $tagdata);	
				}
				else
				{
					$tagdata = $TMPL->swap_var_single($var_key, '', $tagdata);
				}
			}
			
			$output .= $tagdata;
		}
		
		return $output;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Humanize and Localize
	 *
	 * Takes a Github timestamp and returns an EE localized date
	 * Github date reference: 2009-03-31T09:54:51-07:00
	 *
	 * @access	public
	 * @return	string
	 */
	function _humanize_localize($time)
	{
		global $LOC, $SESS;
		
		// Fix formatting
		$time = str_replace('T', ' ', $time);
		$time = substr($time, 0, -6);
		
		// We already have GMT so we need $LOC->convert_human_date_to_gmt to
		// NOT do any localization.  Fib the Session userdata for sec.
		$timezone = $SESS->userdata['timezone'];
		$dst = $SESS->userdata['daylight_savings'];				
		$SESS->userdata['timezone'] = 'UTC';
		$SESS->userdata['daylight_savings'] = 'n';
		
		$time = $LOC->convert_human_date_to_gmt($time);
		
		// reset Session userdata to original values
		$SESS->userdata['timezone'] = $timezone;
		$SESS->userdata['daylight_savings'] = $dst;
		
		return $time;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Fetch data
	 *
	 * Checks cache, gets data, writes cache, returns array of parsed xml
	 *
	 * @access	public
	 * @param	string
	 * @return	mixed
	 */
	function _fetch_data($url)
	{
		global $TMPL;
		
		$cache_expired = FALSE;
		$url = $this->base_url.$url;
		
		// Grab the data
		
		if (($rawxml = $this->_check_cache($url)) === FALSE)
		{
			$cache_expired = TRUE;
			$TMPL->log_item("Fetching Github data remotely");
			
			if ( function_exists('curl_init'))
			{
				$rawxml = $this->_curl_fetch($url); 
			}
			else
			{
				$rawxml = $this->_fsockopen_fetch($url);
			}
		}
		
		if ($rawxml == '' OR substr($rawxml, 0, 5) != "<?xml")
		{
			$TMPL->log_item("Github Error: Unable to retrieve data from: ".$url);
			return FALSE;
		}
		
		// Write the cache file if necessary
		
		if ($cache_expired === TRUE)
		{
			$this->_write_cache($rawxml, $url);			
		}
		
		// Parse the XML
		
		if ( ! class_exists('EE_XMLparser'))
		{
		    require PATH_CORE.'core.xmlparser'.EXT;
		}

		$XML = new EE_XMLparser;
		
		// valid XML?
		if (($xml_obj = $XML->parse_xml($rawxml)) === FALSE)
		{
			$TMPL->log_item("Github Error: Unable to retrieve data from: ".$url);
			return FALSE;
		}

		// Create array from the xml
		$github_data = $this->_parse_xml($xml_obj);
		
		if ( ! is_array($github_data) OR count($github_data) == 0)
		{
			return FALSE;
		}
		
		return $github_data;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Parse XML
	 *
	 * Preps the Github returned xml data
	 *
	 * @access	public
	 * @param	object
	 * @return	array
	 */
	function _parse_xml($xml)
	{
		if (is_array($xml->children) && count($xml->children) > 0)
		{
			$values = array();
			
			foreach($xml->children as $key => $val)
			{
				// Flatten out the children
				if (isset($this->nested[$val->tag]) && is_array($val->children))
				{
					foreach($val->children as $k => $v)
					{
							if (in_array($v->tag, $this->nested[$val->tag]))
							{
								$values[$this->prefix.$val->tag.'-'.$v->tag] = $this->_parse_xml($v);
							}
					}

					// Did something change?
					if (count($values) > 0)
					{
						continue;
					}
				}
				
				if (is_array($val->children) && in_array($val->tag, $this->ignore))
				{
					$values[] = $this->_parse_xml($val);
				}
				else
				{
					$values[$this->prefix.$val->tag] = $this->_parse_xml($val);
				}
			}
			
			return $values;
		}
		elseif ($xml->tag && $xml->value)
		{
			return $xml->value;
		}
		
		return FALSE;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Check Cache
	 *
	 * Check for cached data
	 *
	 * @access	public
	 * @param	string
	 * @return	mixed - string if pulling from cache, FALSE if not
	 */
	function _check_cache($url)
	{	
		global $TMPL;

		/** ---------------------------------------
		/**  Check for cache directory
		/** ---------------------------------------*/
		
		$dir = PATH_CACHE.$this->cache_name.'/';
		$cache = FALSE;
		
		if ( ! @is_dir($dir))
		{
			return FALSE;
		}

        $file = $dir.md5($url);
		
		if ( ! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
		{
			return FALSE;
		}
		       
		flock($fp, LOCK_SH);
		
		$timestamp	= trim(fgets($fp, 30));
		
		if ((strlen($timestamp) != 10))
		{
			$TMPL->log_item("Corrupt Github Cache File: ".$file);
		}
		elseif (time() < ($timestamp + ($this->refresh * 60)))
		{
			$cache = @fread($fp, filesize($file));
			$cache = trim($cache);
		}
		                    
		flock($fp, LOCK_UN);
		fclose($fp);
		
		if ( ! $cache)
		{
			$TMPL->log_item("Empty Github Cache File: ".$file);
			return FALSE;
		}

		// @todo specify what function it used
		$TMPL->log_item("Github data retrieved from cache");
		
        return $cache;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Write Cache
	 *
	 * Write the cached data
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */
	function _write_cache($data, $url)
	{
		/** ---------------------------------------
		/**  Check for cache directory
		/** ---------------------------------------*/
		
		$dir = PATH_CACHE.$this->cache_name.'/';

		if ( ! @is_dir($dir))
		{
			if ( ! @mkdir($dir, 0777))
			{
				return FALSE;
			}
			
			@chmod($dir, 0777);            
		}
		
		// add a timestamp to the top of the file
		$data = time()."\n".$data;
		
		/** ---------------------------------------
		/**  Write the cached data
		/** ---------------------------------------*/
		
		$file = $dir.md5($url);
	
		if ( ! $fp = @fopen($file, 'wb'))
		{
			return FALSE;
		}

		flock($fp, LOCK_EX);
		fwrite($fp, $data);
		flock($fp, LOCK_UN);
		fclose($fp);
        
		@chmod($file, 0777);
	}

	// --------------------------------------------------------------------
	
	/**
	 * curl Fetch
	 *
	 * Fetch supplied uri using curl
	 *
	 * @access	public
	 * @param	string
	 * @return	string
	 */
	function _curl_fetch($url)
	{
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

		$data = curl_exec($ch);
		
		curl_close($ch);

		return $data;
	}

	// --------------------------------------------------------------------
	
	/**
	 * fsockopen Fetch
	 *
	 * Fetch supplied URI using fsockopen
	 *
	 * @access	public
	 * @param	string
	 * @return	string
	 */
	function _fsockopen_fetch($url)
	{
		$target = parse_url($url);

		$data = '';

		$fp = fsockopen($target['host'], 80, $error_num, $error_str, 8); 

		if (is_resource($fp))
		{
			fputs($fp, "GET {$url} HTTP/1.0\r\n");
			fputs($fp, "Host: {$target['host']}\r\n");
			fputs($fp, "User-Agent: EE/EllisLab PHP/" . phpversion() . "\r\n\r\n");

		    $headers = TRUE;

		    while( ! feof($fp))
		    {
		        $line = fgets($fp, 4096);

		        if ($headers === FALSE)
		        {
		            $data .= $line;
		        }
		        elseif (trim($line) == '')
		        {
		            $headers = FALSE;
		        }
		    }

		    fclose($fp); 
		}
		
		return $data;
	}

	// --------------------------------------------------------------------
	
	/**
	 * Usage
	 *
	 * Plugin Usage
	 *
	 * @access	public
	 * @return	string
	 */
	function usage()
	{
		ob_start(); 
		?>

		Work in progress - for now, refer to the README file
		
		--------------------------
		Global Parameters:
		--------------------------
		
		username	- always required
		refresh		- cache refresh time - defaults to 30 (minutes)
		prefix		- single var prefix to avoid conflicts (defaults to blank)
		

		------------------
		Changelog:
		------------------		
		
		Version 0.8 - Initial plugin release
		
		<?php
		$buffer = ob_get_contents();

		ob_end_clean(); 

		return $buffer;
	}

	// --------------------------------------------------------------------
	
}
// END Github Class

/* End of file  pi.github.php */
/* Location: ./system/plugins/pi.github.php */