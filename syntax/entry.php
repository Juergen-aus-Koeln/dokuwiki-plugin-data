<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once(dirname(__FILE__).'/../syntaxbase.php');

/**
 * We extend our own base class here
 */
class syntax_plugin_data_entry extends syntaxbase_plugin_data {

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *data *-+\n.*?\n----+',$mode,'plugin_data_entry');
    }


    /**
     * Handle the match - parse the data
     */
    function handle($match, $state, $pos, &$handler){
        // get lines
        $lines = explode("\n",$match);
        array_pop($lines);
        array_shift($lines);

        // parse info
        $data = array();
        $meta = array();
        foreach ( $lines as $line ) {
            // ignore comments
            $line = preg_replace('/(?<!&)#.*$/','',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/',$line,2);
            // multiple values allowed? - vals may be comma separated or in multiple lines
            if(substr($line[0],-1) == 's'){
                list($key,$type) = explode('_',substr($line[0],0,-1),2);
                $key = utf8_strtolower($key);
                if(!is_array($data[$key])) $data[$key] = array(); // init with empty array
                $vals = explode(',',$line[1]);
                $data[$key] = array_merge($data[$key],$vals);
                $data[$key] = array_map('trim',$data[$key]);
                $data[$key] = array_filter($data[$key]);
                #fixme call cleandata here
                array_unique($data[$key]);
                $meta[$key]['multi'] = 1;
            }else{
                list($key,$type) = explode('_',$line[0],2);
                $key = utf8_strtolower($key);
                $data[$key]          = $this->_cleanData($line[1],$type);
                $meta[$key]['multi'] = 0;
            }
            $meta[$key]['type'] = $type;
        }
        return array('data'=>$data, 'meta'=>$meta);
    }

    /**
     * Create output or save the data
     */
    function render($format, &$renderer, $data) {
        global $ID;

        switch ($format){
            case 'xhtml':
                $renderer->doc .= $this->_showData($data,$renderer);
                return true;
            case 'metadata':
                $this->_saveData($data,noNS($ID),$renderer->meta['title']);
                return true;
            default:
                return false;
        }
    }

    /**
     * Output the data in a table
     */
    function _showData($data,&$R){
        $ret = '';

        $ret .= '<table class="inline dataplugin_data">';
        foreach($data['data'] as $key => $val){
            $ret .= '<tr>';
            $ret .= '<th>'.hsc($key).'</th>';
            $ret .= '<td>';
            if(is_array($val)){
                $ret .= '<ul>';
                foreach ($val as $v){
                    $ret .= '<li><div class="li">';
                    $ret .= $this->_formatData($v, $data['meta'][$key]['type'], $R);
                    $ret .= '</div></li>';
                }
                $ret .= '</ul>';
            }else{
                $ret .= $this->_formatData($val, $data['meta'][$key]['type'], $R);
            }
            $ret .= '</td>';
            $ret .= '</tr>';
        }
        $ret .= '</table>';
        return $ret;
    }

    /**
     * Save date to the database
     */
    function _saveData($data,$id){
        if(!$this->_dbconnect()) return false;

        $error = '';
        $id    = sqlite_escape_string($id);

        // begin transaction
        $sql = "BEGIN TRANSACTION";
        sqlite_exec($this->db,$sql);

        // store page info
        $sql = "INSERT OR IGNORE INTO pages (page) VALUES ('$id')";
        sqlite_exec($this->db,$sql,$error);

        // fetch page id
        $sql = "SELECT pid FROM pages WHERE page = '$id'";
        $res = sqlite_query($this->db, $sql);
        $pid = (int) sqlite_fetch_single($res);

        if(!$pid){
            msg("data plugin: failed saving data ($error)",-1);
            return false;
        }

        // update meta info
        foreach ($data['meta'] as $key => $info){
            $key   = sqlite_escape_string($key);
            $type  = sqlite_escape_string($info['type']);
            $multi = (int) $info['multi'];

            $sql = "REPLACE INTO meta (key, type, multi) VALUES ('$key', '$type', '$multi')";
            sqlite_exec($this->db,$sql);
        }

        // remove old data
        $sql = "DELETE FROM data WHERE pid = $pid";
        sqlite_exec($this->db,$sql);

        // insert new data
        foreach ($data['data'] as $key => $val){
            $k = sqlite_escape_string($key);
            if(is_array($val)) foreach($val as $v){
                $v   = sqlite_escape_string($v);
                $sql = "INSERT INTO data (pid, key, value) VALUES ($pid, '$k', '$v')";
                sqlite_exec($this->db,$sql);
            }else {
                $v   = sqlite_escape_string($val);
                $sql = "INSERT INTO data (pid, key, value) VALUES ($pid, '$k', '$v')";
                sqlite_exec($this->db,$sql);
            }
        }

        // finish transaction
        $sql = "COMMIT TRANSACTION";
        sqlite_exec($this->db,$sql);

        sqlite_close($this->db);
        return true;
    }

}