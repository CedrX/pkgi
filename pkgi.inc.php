<?php

class Pkgi
{
    var $APPNAME = '';
    var $MODULES = array();
    var $MODULES_LIST = array();

    var $env_path = null;
    var $tpl_path = null;
    var $dst_path = null;
    var $php_path = null;
    var $version  = null;
    var $sys_pkg_query = 'dpkg --get-selections %s 2> /dev/null | grep -E "install|hold"';
    var $options = array();

    function Pkgi($env_path = null, $tpl_path = null, $dst_path = null, $options = array())
    {
        $current_dir = substr(dirname(__FILE__), strrpos(dirname(__FILE__),'/')+1);
        if (!preg_match('/^[a-z]+$/i',$current_dir)) $current_dir = 'src';
        $this->env_path = ($env_path == null) ? dirname(__FILE__).'/../'.$current_dir.'.env' : $env_path;
        $this->tpl_path = ($tpl_path == null) ? realpath(dirname(__FILE__)) : $tpl_path;
        $this->dst_path = $dst_path;
        $this->php_path = getenv('PHP');
        $this->version  = trim(file_get_contents(dirname(__FILE__).'/version'));
        if ($this->php_path === false)  $this->php_path = '/usr/bin/php'; 
        $this->options = $options;
    }
  
    function run()
    {
        if (in_array('--help',$this->options) || in_array('-h',$this->options)) {
            echo "Options de pkgi-".$this->version." :\n";
            echo "  --modules=[[m1,m2,...]]\n";
            echo "    Force le chargement des modules passés en argument.\n";
            echo "  --no-dep\n";
            echo "    Ne fait pas les vérifications avec les dépendances systèmes.\n";
            echo "  --force-overwrite\n";
            echo "    Ne pose pas de question si une instance d'un fichier a été modifiée localement.\n";
            echo "  --version\n";
            echo "    Affiche le numéro de version de pkgi.\n";
            echo "  --help\n";
            echo "    Affiche cette page.\n";
            die();
        }

        if (in_array('--version',$this->options)) {
            echo $this->version."\n";
            die();
        }
    
        echo "--- Choisissez un nom d'application\n";
        $this->choose_appli_name();
        echo "Le nom d'application suivant sera utilisé : ".$this->APPNAME."\n";
        echo "--- Choisissez les modules à activer\n";
        $this->build_module_list();
        $this->choose_modules();
        if (count($this->MODULES) > 1)
            echo "Les modules suivants seront utilisés : ".implode(',',$this->MODULES)."\n";
        else
            echo "Le module suivant sera utilisé : ".implode(',',$this->MODULES)."\n";

        echo "--- Vérifications des dépendances\n";
        if (!in_array('--no-dep',$this->options)) {
            $this->check_dependencies();
        } else {
            echo "Vérifications des dépendances ignorées à la demande de l'utilisateur : option --no-dep\n";
        }

        echo "--- Chargement des variables d'environnement\n";
        $env = array();
        $this->load_env($env);
        $this->check_env($env);
        $this->write_env($env);
        $this->load_extra_env($env); // a faire apres write_env car on veut pas les sauvegarder

        // get the APPLI_HOME from the env
        if ($this->dst_path == null)
        {
            $dst = $env[$this->APPNAME.'_HOME'];
            if (!file_exists($dst)) @mkdir($dst, 0777, true);
            if (file_exists($dst))
                $this->dst_path = $dst;
            else
                die("$dst doesn't exist");
        }
    
        echo "--- Instanciation des templates\n";
        $this->write_tpl_instance();

        echo "* Votre application ".$this->APPNAME." est prête.\n";
        echo "* Elle utilise les modules suivants : ".implode(',',$this->MODULES)."\n";
        echo "* Les paramètres ont été sauvegardés dans : ".realpath($this->env_path)."\n";
        echo "* Vous pouvez à tout moment modifier un parametre.\n";
        echo "* Pensez alors à relancer le build pour régénérer les fichiers de conf et les lanceurs.\n";
    }

    function build_module_list()
    {
        $this->MODULES_LIST = array();
        $dir = dirname(__FILE__);
        $d = opendir($dir);
        while ($file = readdir($d))
        {
            if ($file == '.' || $file == '..' ||
                $file == 'CVS' || $file == '.svn' ||
                $file == 'core')
                continue;
            else if (is_dir($dir.'/'.$file))
                $this->MODULES_LIST[] = $file;
        }
        closedir($d);
    }
  
    function choose_modules()
    {
        // on commence par chercher si on a indiqué explicitement
        // quels modules utiliser dans la ligne de commande
        foreach($this->options as $o) {
            if (preg_match('/^--modules=(.*)/',$o,$matched)) {
                $this->MODULES = $this->_filter_valide_modules(explode(',',$matched[1]));
            }
        }

        if (count($this->MODULES) == 0) {
            // on recherche ensuite les modules dans les variables d'environement
            if ($s = getenv($this->APPNAME.'_MODULES')) {
                $this->MODULES = $this->_filter_valide_modules(explode(',',$s));
            } else if (file_exists($this->env_path)) {
                // si rien n'a été trouvé alors on cherche dans le fichier pkgi.en
                $data = file_get_contents($this->env_path);
                if (preg_match('/'.$this->APPNAME.'_MODULES=(.+)/i',$data,$res))
                    $this->MODULES = $this->_filter_valide_modules(explode(',',trim($res[1],'" ')));
            }
        }
    
        // si rien n'a ete trouve alors on demande a l'utilisateur d'entrer des modules au clavier
        while (count($this->MODULES) == 0) {
            $prompt = "Entrez le nom des modules separes par des virgules que vous voulez activer dans votre application\nparmis les modules suivants ".implode(',',$this->MODULES_LIST)." : ";
            $this->MODULES = $this->_filter_valide_modules(explode(',',readline($prompt)));
        }
    
        // ajoute le module core dont tous les autres dependent en tout premier de la liste
        $this->MODULES = array_merge(array('core'), $this->MODULES);
    }

    function check_dependencies()
    {
        $deptree = $this->_build_dependency_tree();
        $depresult = array();
        $error = false;

        foreach($deptree as $m => $mdep) {

                if (count($mdep['mandatory-sys-dependency']) > 0 ||
                    count($mdep['mandatory-pkgi-dependency']) > 0) {
                // checking system mandatory dependencies
                foreach($mdep['mandatory-sys-dependency'] as $package) {
                    $output = trim(shell_exec(sprintf($this->sys_pkg_query, $package)));
                    if (empty($output)) {
                        $depresult['mandatory-sys-dependency'][$package][] = $m;
                        $error = true;
                    }
                }
                // checking pkgi mandatory dependencies
                foreach($mdep['mandatory-pkgi-dependency'] as $package) {
                    if (!in_array($package, $this->MODULES)) {
                        $depresult['mandatory-pkgi-dependency'][$package][] = $m;
                        $error = true;
                    }
                }
            }

            if (count($mdep['optional-sys-dependency']) > 0 ||
                count($mdep['optional-pkgi-dependency']) > 0) {
                // checking system optional dependencies
                foreach($mdep['optional-sys-dependency'] as $package) {
                    $output = trim(shell_exec(sprintf($this->sys_pkg_query, $package)));
                    if (empty($output)) {
                        $depresult['optional-sys-dependency'][$package][] = $m;
                    }
                }
                // checking pkgi mandatory dependencies
                foreach($mdep['optional-pkgi-dependency'] as $package) {
                    if (!in_array($package, $this->MODULES)) {
                        $depresult['optional-pkgi-dependency'][$package][] = $m;
                    }
                }
            }

        }

        if (isset($depresult['optional-pkgi-dependency'])) {
            foreach($depresult['optional-pkgi-dependency'] as $k => $v) {
                echo "=> Le module pkgi '$k' est optionnel, d'autre modules pkgi (".implode(',',$v).") peuvent l'utiliser.\n";
            }
        }
        if (isset($depresult['optional-sys-dependency'])) {
            foreach($depresult['optional-sys-dependency'] as $k => $v) {
                echo "=> Le packet système '$k' est optionnel, les modules pkgi (".implode(',',$v).") peuvent l'utiliser.\n";
            }
        }
        if (isset($depresult['mandatory-pkgi-dependency'])) {
            foreach($depresult['mandatory-pkgi-dependency'] as $k => $v) {
                echo "=> Le module pkgi '$k' n'est pas installé, il doit l'être pour pouvoir utiliser les modules pkgi suivants : ".implode(',',$v).".\n";
            }
        }
        if (isset($depresult['mandatory-sys-dependency'])) {
            foreach($depresult['mandatory-sys-dependency'] as $k => $v) {
                echo "=> Le packet système '$k' n'est pas installé, il doit l'être pour pouvoir utiliser les modules pkgi suivants : ".implode(',',$v).".\n";
            }
        }

        if ($error) die();
    }

    function _filter_valide_modules($modules_to_check)
    {
        // filtre les modules suivant la liste des modules disponibles
        $mod_ok = array();
        foreach($modules_to_check as $m)
            if (in_array($m, $this->MODULES_LIST))
                $mod_ok[] = $m;
        return $mod_ok;
    }
  
    function choose_appli_name()
    {
        // on recherche APPNAME dans l'environement
        // si on le trouve pas alors on cherche dans le fichier XXXX.env
        if ($s = getenv('APPNAME')) {
            $this->APPNAME = $s;
        } else if (file_exists($this->env_path)) {
            $data = file_get_contents($this->env_path);
            if (preg_match('/APPNAME=(.+)/i',$data,$res))
                $this->APPNAME = trim($res[1],'" ');
        }

        // rien n'a ete trouve alors on demande a l'utilisateur de l'entrer au clavier
        if ($this->APPNAME == '')
        {
            do {
                $prompt = "Entrez le nom de votre application (en lettres majuscules): ";
                $this->APPNAME = readline($prompt);
            } while (!preg_match('/[A-Z]+/',$this->APPNAME));
        }
    }


    /**
     * Charge dans le tableau passé en parametre toutes les variables d'env trouvées
     * soit dans l'environement courant, soit dans le fichier XXX.env
     */
    function load_env(&$env)
    {
        $env['APPNAME'] = $this->APPNAME;
        putenv('APPNAME='.$this->APPNAME);
        $env[$this->APPNAME.'_MODULES'] = implode(',',$this->MODULES);

        // construit une liste des variables d'env a tester
        $env_to_check = $this->_build_env_to_check();
    
        $data = file_exists($this->env_path) ? file_get_contents($this->env_path) : '';
        foreach($env_to_check as $e => $e_option)
        {
            $e_unnamed = 'APPNAME_'.$e;
            $e = $this->APPNAME.'_'.$e;
            if (getenv($e) === FALSE) {
                if (preg_match('/export\s+'.$e.'=(.*)/',$data,$res)) {
                    $env[$e] = trim($res[1],'" ');
                    putenv($e.'='.$env[$e]);
                }
            } else {
                $env[$e] = getenv($e);
            }
            if (isset($env[$e])) {
                putenv($e_unnamed.'='.$env[$e]);
            }
        }
    }

    function _build_env_to_check($modules = null)
    {
        // construit une liste des variables d'env a tester
        // en fonction des modules choisis
        $env_to_check = array();
        if ($modules === NULL) {
            $modules = $this->MODULES;
        }
        foreach($modules as $m)
        {
            $ini_path = dirname(__FILE__).'/'.$m.'/config.ini';
            if (!file_exists($ini_path)) continue;

            // execute les balises php eventuelles contenues dans config.ini
            $output = shell_exec($this->php_path.' '.$ini_path);
            $ini_path = dirname(__FILE__).'/config.ini.tmp';
            file_put_contents($ini_path,$output);

            $ini_data = parse_ini_file($ini_path);
            if (isset($ini_data['env'])) {
                for ($i = 0 ; $i<count($ini_data['env']) ; $i++)
                {
                    $env_to_check[$ini_data['env'][$i]] = array();
                    $env_to_check[$ini_data['env'][$i]][] = $ini_data['env-desc'][$i];
                    $env_to_check[$ini_data['env'][$i]][] = $ini_data['env-choix'][$i] != '' ? explode(',',$ini_data['env-choix'][$i]) : array();
                    $env_to_check[$ini_data['env'][$i]][] = isset($ini_data['env-default'][$i]) ? $ini_data['env-default'][$i] : '';
                }
            }
            unlink($ini_path);
        }

        return $env_to_check;
    }


    function _build_dependency_tree()
    {
        // construit une liste des dépendances entre les différents modules
        $dep = array();
        foreach($this->MODULES as $m)
        {
            $ini_path = dirname(__FILE__).'/'.$m.'/config.ini';
            if (!file_exists($ini_path)) continue;

            // execute les balises php eventuelles contenues dans config.ini
            $output = shell_exec($this->php_path.' '.$ini_path);
            $ini_path = dirname(__FILE__).'/config.ini.tmp';
            file_put_contents($ini_path,$output);
      
            $ini_data = parse_ini_file($ini_path);
            foreach(array('mandatory-sys-dependency',
                          'optional-sys-dependency',
                          'mandatory-pkgi-dependency',
                          'optional-pkgi-dependency') as $field) {
                $dep[$m][$field] = array();
                if (isset($ini_data[$field]) && is_array($ini_data[$field])) {
                    foreach($ini_data[$field] as $v) {
                        $v = trim($v);
                        if (!empty($v)) $dep[$m][$field][] = $v;
                    }
                }
            }
            unlink($ini_path);
        }

        return $dep;
    }
  
    /**
     * Verifie que les variables d'environement passées en paramètre
     * sont bien définies et qu'il n'en manque pas suivant les modules selectionnés
     */
    function &check_env(&$env)
    {
        echo "Verification de la presence des variables d'environnement ...\n";
    
        // construit une liste des variables d'env a tester
        foreach($this->MODULES as $m) {
            $env_to_check = $this->_build_env_to_check(array($m));
            foreach($env_to_check as $e => $e_option)
            {
                $e_unnamed = 'APPNAME_'.$e;
                $e         = $this->APPNAME.'_'.$e;
                $v = isset($env[$e]) ? $env[$e] : NULL;
                if ($v == NULL || $v == '')
                {
                    $v = getenv($e);
                    if ($v === FALSE || $v == '') 
                    {
                        echo "\n";
                        echo "Signification de $e : ".$e_option[0]."\n";
                        if (count($e_option[1]) > 0)
                            echo "Valeurs possibles de $e : ".implode(' ou ', $e_option[1])."\n";
                        $v_default = $e_option[2] != '' ? "[defaut=".$e_option[2]."] " : '';
                        $prompt = "La variable $e est indefinie, entrez sa valeur ".$v_default.": ";
                        $v = readline($prompt);
                        if ($v == '') $v = $e_option[2]; // si on a rien repondu, on prend la valeur par defaut
                    }
                    $env[$e] = $v;
                    putenv("$e=$v");
                    putenv("$e_unnamed=$v");
                }
                echo "La variable suivante sera utilisée : $e=$v\n";
            }
        }
        return $env;
    }


    function write_env($env)
    {
        $filename = $this->env_path;
        echo "Ecriture des variables d'environnement dans ".realpath($filename)."\n";
        $data = '';
        foreach($env as $k => $v)
        {
            // ecriture dans le fichier XXX.env
            $data .= sprintf("export %s=\"%s\"\n", $k, $v);
      
            // ecriture dans l'environnement
            // on replace tout les prefixes par APPNAME car c'est le prefix des variables d'env dans nos templates
            if (preg_match('/'.$this->APPNAME.'_(.+)/',$k,$res))
                putenv("APPNAME_".$res[1]."=$v");
        }
        $set_fmode = (!file_exists($filename));
        file_put_contents($filename,$data);
        if ($set_fmode) {
            // si le fichier d'env est ecrit pour la premiere fois on regle les droits pour une bonne sécurité
            chmod($filename, 0600);
        }
    }

    /**
     * Charge :
     * APPNAME_DSTART_LIST
     * APPNAME_DSTOP_LIST
     * APPNAME_DRESTART_LIST
     * APPNAME_DRELOAD_LIST
     * APPNAME_DSTATUS_LIST
     * APPNAME_ENV_FILE_PATH
     */
    function load_extra_env(&$env)
    {
        // construit une liste des demons a demarrer et arreter
        // cette liste sera utilisee par appli pour lancer/arreter d'un coups tous les demons
        $dstart_list   = array();
        $dstop_list    = array();
        $drestart_list = array();
        $dstatus_list  = array();
        $dreload_list  = array();
        foreach($this->MODULES as $m)
        {
            $ini_path = dirname(__FILE__).'/'.$m.'/config.ini';
            if (!file_exists($ini_path)) continue;
            $ini_data = parse_ini_file($ini_path);
            if (isset($ini_data['start-daemon']) && $ini_data['start-daemon'] != '')
                $dstart_list[$m] = $ini_data['start-daemon'];
            if (isset($ini_data['stop-daemon']) && $ini_data['stop-daemon'] != '')
                $dstop_list[$m]  = $ini_data['stop-daemon'];
            if (isset($ini_data['restart-daemon']) && $ini_data['restart-daemon'] != '')
                $drestart_list[$m]  = $ini_data['restart-daemon'];
            if (isset($ini_data['status-daemon']) && $ini_data['status-daemon'] != '')
                $dstatus_list[$m]  = $ini_data['status-daemon'];
            if (isset($ini_data['reload-daemon']) && $ini_data['reload-daemon'] != '')
                $dreload_list[$m]  = $ini_data['reload-daemon'];
        }
        $dstart_list   = serialize($dstart_list);
        $dstop_list    = serialize($dstop_list);
        $drestart_list = serialize($drestart_list);
        $dstatus_list  = serialize($dstatus_list);
        $dreload_list  = serialize($dreload_list);

        putenv('APPNAME_DSTART_LIST='.$dstart_list);
        putenv('APPNAME_DSTOP_LIST='.$dstop_list);
        putenv('APPNAME_DRESTART_LIST='.$drestart_list);
        putenv('APPNAME_DSTATUS_LIST='.$dstatus_list);
        putenv('APPNAME_DRELOAD_LIST='.$dreload_list);

        // ajout du chemin vers le fichier d'environement cree
        putenv('APPNAME_ENV_FILE_PATH='.realpath($this->env_path));
    }


    function build_templates_list()
    {
        $ret = array();
        foreach($this->MODULES as $m)
        {
            $list[$m] = array_values(ls($this->tpl_path.'/'.$m,"//i"));
            $n = 0;
            foreach( $list[$m] as $l)
            {
                $list[$m][$n] = str_replace($this->tpl_path.'/'.$m.'/', '', $list[$m][$n]);
                if (is_file($l))
                    if ( dirname($l) == $this->tpl_path )
                        unset($list[$m][$n]);
                if (trim($list[$m][$n]) == '' ||
                    trim($list[$m][$n]) == '/' ||
                    // ne liste pas config.ini dans les templates a instancier
                    // car c'est un simple fichier de description
                    $list[$m][$n] == 'config.ini')
                    unset($list[$m][$n]);
                $n++;
            }
            sort($list[$m]);
            $ret = array_merge($ret,$list);
        }
        return $ret;
    }

    // WRITE TPL INSTANCES
    function write_tpl_instance()
    {
        $tlist = $this->build_templates_list();

        // first we check that modified files will not be overwriten
        $modified_file = array();
        foreach($tlist as $m => $templates) {
            foreach($templates as $t) {
                if ($t == 'pkgi.env') continue; // special case for this file, do not touch it ! 

                $t_dst     = $this->dst_path.'/'.$t;
                $t_dst_md5 = $this->dst_path.'/.pkgi/lastmd5/'.$t;

                // traitement permettant de gérer les extensions .pkgi-raw
                // fichiers ou répertoires à ne pas traiter comme des templates
                $t_dst     = str_replace('.pkgi-raw','',$t_dst);
                $t_dst_md5 = str_replace('.pkgi-raw','',$t_dst_md5);

                if (is_link($t_dst)) {
                    // handle symlinks
                    if (!is_link($t_dst_md5)) {
                        $modified_file[] = $t_dst;
                    } else if (file_exists($t_dst_md5) && readlink($t_dst) != readlink($t_dst_md5)) {
                        $modified_file[] = $t_dst;
                    }
                } else if (is_dir($t_dst)) {
                } else if (is_file($t_dst)) {
                    // handle files
                    if (file_exists($t_dst) &&
                        file_exists($t_dst_md5)) {
                        $md5_current   = md5(file_get_contents($t_dst));
                        $md5_lastbuild = file_get_contents($t_dst_md5);
                        if ($md5_current != $md5_lastbuild)
                            $modified_file[] = $t_dst;
                    }
                }

            }
        }

        if (count($modified_file) > 0 && !in_array('--force-overwrite',$this->options))
        {
            do {
                $prompt = "Les fichiers suivants ont été modifié manuellement depuis le dernier build :\n".
                    implode("\n",$modified_file)."\n".
                    "Voulez vous les écraser (o/n) ? :\n";
                $answer = readline($prompt);
            } while (!preg_match('/^[on]+/i',$answer));
            if (preg_match('/^n/i',$answer))
                die("Build interrompu !\n");
        }
    
        // then we instanciate the templates
        foreach($tlist as $m => $templates)
            foreach($templates as $t)
            {
                if ($t == 'pkgi.env') continue; // special case for this file, do not touch it ! 

                $t_src     = $this->tpl_path.'/'.$m.'/'.$t;
                $t_dst     = $this->dst_path.'/'.$t;
                $t_dst_md5 = $this->dst_path.'/.pkgi/lastmd5/'.$t;

                // traitement permettant de gérer les extensions .pkgi-raw
                // fichiers ou répertoires à ne pas traiter comme des templates
                $t_src_notpl = (strpos($t_src,'.pkgi-raw') !== false);
                $t_dst     = str_replace('.pkgi-raw','',$t_dst);
                $t_dst_md5 = str_replace('.pkgi-raw','',$t_dst_md5);

                // Vérifie si on doit ignorer le fichier ou le répertoire
                $t_src_ignore = (strpos($t_src,'.pkgi-ignore') !== false);
                if ($t_src_ignore) {
                    continue;
                }

                echo "Ecriture de ".$t_dst."\n";
                if (file_exists($t_src) && !is_dir($t_src) && !is_link($t_src)) {
                    @mkdir(dirname($t_dst), 0777, true);
                    if ($t_src_notpl) {
                        $output = file_get_contents($t_src);
                    } else {
                        $output = shell_exec($this->php_path.' '.$t_src);
                    }
                    @unlink($t_dst);
                    file_put_contents($t_dst, $output);
                    // setting the rights
                    if (is_executable($t_src) || preg_match('/^bin\//',$t)) {
                        chmod($t_dst,0700);
                    } else {
                        chmod($t_dst,0600);
                    }
                    // store the file md5
                    @mkdir(dirname($t_dst_md5), 0777, true);
                    @unlink($t_dst_md5);
                    file_put_contents($t_dst_md5, md5($output));
                } else if (is_link($t_src)) {
                    // manage symlinks
                    @unlink($t_dst);
                    @mkdir(dirname($t_dst), 0777, true);
                    symlink(readlink($t_src),$t_dst);
                    @unlink($t_dst_md5);
                    @mkdir(dirname($t_dst_md5), 0777, true);
                    symlink(readlink($t_src),$t_dst_md5);
                } else if (substr($t_src,-1) == '/') {
                    @mkdir($t_dst, 0777, true);
                } else {
                    trigger_error($t_src." cannot be found",E_USER_ERROR);
                }
            }
    }

}






function ls($dir, $mask /*.php$|.txt$*/)
{
    static $i = 0;
    $files = Array();
    $d = opendir($dir);
    $empty = true;
    while ($file = readdir($d))
    {
        if ($file == '.' || $file == '..' ||
            $file == 'CVS' || $file == '.svn' || $file == '.dummy' || preg_match('/~$/',$file) ||
            !preg_match($mask, $file)) continue;
        $empty = false;
        if (is_dir($dir.'/'.$file) && !is_link($dir.'/'.$file))
        {
            $files += ls($dir.'/'.$file, $mask);
            continue;
        }
        $files[$i++] = $dir.'/'.$file;
    }
    closedir($d);
    if ($empty) $files[$i++] = $dir.'/';
    return $files;
}



if (!function_exists('readline'))
{
    function readline($prompt)
    {
        echo $prompt;
        $in = trim(fgets(STDIN)); // Maximum windows buffer size
        return $in;
    }
}


/**
 * Replace file_put_contents()
 *
 * @category    PHP
 * @package     PHP_Compat
 * @link        http://php.net/function.file_put_contents
 * @author      Aidan Lister <aidan@php.net>
 * @version     $Revision: 1.2 $
 * @internal    resource_context is not supported
 * @since       PHP 5
 * @require     PHP 4.0.0 (user_error)
 */
if (!defined('FILE_USE_INCLUDE_PATH')) { define('FILE_USE_INCLUDE_PATH', 1); }
if (!defined('LOCK_EX')) { define('LOCK_EX', 2); }
if (!defined('FILE_APPEND')) { define('FILE_APPEND', 8); }
if (!function_exists('file_put_contents')) {
    function file_put_contents($filename, $content, $flags = null, $resource_context = null)
        {
            // If $content is an array, convert it to a string
            if (is_array($content)) {
                $content = implode('', $content);
            }

            // If we don't have a string, throw an error
            if (!is_scalar($content)) {
                user_error('file_put_contents() The 2nd parameter should be either a string or an array',
                           E_USER_WARNING);
                return false;
            }

            // Get the length of data to write
            $length = strlen($content);

            // Check what mode we are using
            $mode = ($flags & FILE_APPEND) ?
                'a' :
                'wb';

            // Check if we're using the include path
            $use_inc_path = ($flags & FILE_USE_INCLUDE_PATH) ?
                true :
                false;

            // Open the file for writing
            if (($fh = @fopen($filename, $mode, $use_inc_path)) === false) {
                user_error('file_put_contents() failed to open stream: Permission denied',
                           E_USER_WARNING);
                return false;
            }

            // Attempt to get an exclusive lock
            $use_lock = ($flags & LOCK_EX) ? true : false ;
            if ($use_lock === true) {
                if (!flock($fh, LOCK_EX)) {
                    return false;
                }
            }

            // Write to the file
            $bytes = 0;
            if (($bytes = @fwrite($fh, $content)) === false) {
                $errormsg = sprintf('file_put_contents() Failed to write %d bytes to %s',
                                    $length,
                                    $filename);
                user_error($errormsg, E_USER_WARNING);
                return false;
            }

            // Close the handle
            @fclose($fh);

            // Check all the data was written
            if ($bytes != $length) {
                $errormsg = sprintf('file_put_contents() Only %d of %d bytes written, possibly out of free disk space.',
                                    $bytes,
                                    $length);
                user_error($errormsg, E_USER_WARNING);
                return false;
            }

            // Return length
            return $bytes;
        }
}


?>
