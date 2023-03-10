<?php
/*
Plugin Name: The Sports DB
Description: Plugin to display athlete's information retrieved from TheSportsDB website (https://www.thesportsdb.com)
*/

class The_Sports_DB {
	
	private static $instance;
 
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
 
        return self::$instance;
    }
	
	public function __construct() {
		
		global $wpdb;
		
		$this->wpdb = $wpdb;
		
		$this->table_name = $wpdb->prefix . 'the_sports_db';
		
		$this->api_url = 'https://www.thesportsdb.com/api/v1/json/3/lookupplayer.php?id=';
		
		$this->cache_key = 'sports-db-player-';
		
		$this->plugin = 'the-sports-db';
		
		$this->errors = [
			'ids' => "L'ID suivant n'existe pas :\n",
			'api' => "Une erreur s'est produite avec l'API de The Sports DB",
			'db'  => "Une erreur s'est produite durant l'insertion dans la base de données"
		];
		
		add_shortcode('athlete', array( &$this, 'shortcode'));
		
		add_action('admin_enqueue_scripts', array( &$this, 'scripts'));
		
		add_action('init', array( &$this, 'table_install'));
		
		add_action('wp_ajax_curl_sports_db', array( &$this, 'curl'));
		
		add_action('wp_footer', array( $this, 'css'));
		
	}
	
	// cURL to retrieve info from The Sports DB website
	public function curl() {
	
		if ( ! defined( 'WP_ADMIN' ) || ! WP_ADMIN )
			return false;
		
		if(!empty($_POST['ids'])) {
			
			$error = '';
			
			$ids = explode(',', $_POST['ids']);
			
			foreach($ids as $id) {
				
				$id = (int)$id;
				
				$player = $this->wpdb->get_row( "SELECT * FROM $this->table_name WHERE pid = $id" );

				if(empty($player)) {
				
					$url = $this->api_url.$id;
					
					$curl = curl_init();
					curl_setopt($curl, CURLOPT_URL, $url);
					curl_setopt($curl, CURLOPT_HTTPHEADER, array(
						"Content-Type: application/json; charset=utf-8"
					));
					curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
					curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					$result = curl_exec($curl);
					curl_close($curl);
					
					if(is_string($result)) {
						
						$data = json_decode($result);
						
						if(empty($data->players)) {
							
							// issue with ID athlete
							$error = 'ids';
							$this->errors['ids'] .= $id;

						} else {
							
							$data = $data->players[0];
							
							$r = $this->wpdb->insert(
								$this->table_name,
								array(
									'pid' => $id,
									'name' => $data->strPlayer,
									'sport' => $data->strSport,
									'nationality' => $data->strNationality,
									'team' => $data->strTeam,
									'height' => $data->strHeight,
									'weight' => $data->strWeight,
									'birthday' => $data->dateBorn,
									'facebook' => $data->strFacebook,
									'twitter' => $data->strTwitter,
									'instagram' => $data->strInstagram,
									'image' => (string)$data->strCutout
								),
								array(
									'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
								)
							);
							
							// issue during insertion in database
							if($r === false) {
								$error = 'db';
							}

						}
						
					} else {

						// issue with The Sports DB API
						$error = 'api';
					}
					
					if(empty($error)) {

						// API Limits
						// Developers must not send more than 1 API request per 2 seconds				
						sleep(2);
					
					} else break;
					
				}
				
			}
			
			if(empty($error)) 
				echo json_encode(array('type' => 'success'));
			else
				echo json_encode(array('type' => 'error', 'error' => $error, 'errors' => $this->errors ));
			
		}
		
		die;
		
	}
	
	// Create table in database
	public function table_install() {
		
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			pid int(15) NOT NULL,
			name tinytext NOT NULL,
			sport tinytext NOT NULL,
			nationality tinytext NOT NULL,
			team tinytext NOT NULL,
			height tinytext NOT NULL,
			weight tinytext NOT NULL,
			birthday date NOT NULL,
			facebook varchar(255) NOT NULL,
			twitter varchar(255) NOT NULL,
			instagram varchar(255) NOT NULL,
			image varchar(255) NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";

		if ( $wpdb->query( $sql ) === false ) {
			throw new Exception( $wpdb->last_error );
		}

	}
	
	// JS file for back-end
	public function scripts() {
		
		$v = false;
		$temp_file = plugin_dir_path( __FILE__ ) . 'assets/js/script.js';
		$v = filemtime($temp_file);
		
		wp_enqueue_script( $this->plugin.'-js',  plugins_url( 'assets/js/script.js' , __FILE__). ($v !== false ? '?v='.$v : '') );
	
	}
	
	// CSS file for front-end
	public function css() {
		
		$v = false;
		$temp_file = plugin_dir_path( __FILE__ ) . 'assets/css/style.css';
		$v = filemtime($temp_file);
		
		wp_register_style( $this->plugin.'-css', plugins_url( 'assets/css/style.css' , __FILE__). ($v !== false ? '?v='.$v : ''), array(), null, 'all');
		
		if( wp_style_is($this->plugin.'-css', 'enqueued') == false)
			wp_enqueue_style( $this->plugin.'-css' );
		
	}
	
	// Retrieve athlete info from database
	public function get_player($id) {
		
		$data = get_transient( $this->cache_key.$id );

		if($data === false) {
			$data = $this->wpdb->get_row( "SELECT * FROM $this->table_name WHERE pid = $id" );
			if(!empty($data))
				set_transient( $this->cache_key.$id, $data );
		}
		
		return $data;
	}
	
	// Normalize data
	public function normalize($data) {
		
		if(strtolower($data->nationality) == 'united states' || strtolower($data->nationality) == 'united states of america')
			$data->nationality = 'USA';
		
		if(!empty($data->birthday)) {
			$birthday = new DateTime($data->birthday);
			$current  = new DateTime(date("Y-m-d"));
			$data->age = $current->diff($birthday)->y;
		}
		
		$data->birthday = strftime('%d/%m/%Y',strtotime($data->birthday));
		
		if(strpos(strtoupper($data->team),'_RETIRED') !== false) {
			$data->team = '';
		}
		
		if(!empty($data->height))
			$data->height = (strpos(strtolower($data->height),'m')===false) ? $data->height. ' m' : $data->height;
		
		if(!empty($data->weight))
			$data->weight = (strpos(strtolower($data->weight),'kg')===false) ? $data->weight. ' kg' : $data->weight;
		
		if(!empty($data->facebook)) {
			$z=explode('/',$data->facebook);
			while(empty(end($z))) {
				array_pop($z);
			}
			$data->facebook = 'https://facebook.com/'.end($z);
		}
		
		if(!empty($data->twitter)) {
			$z=explode('/',$data->twitter);
			while(empty(end($z))) {
				array_pop($z);
			}
			$data->twitter = 'https://twitter.com/'.end($z);
		}
		
		if(!empty($data->instagram)) {
			$z=explode('/',$data->instagram);
			while(empty(end($z))) {
				array_pop($z);
			}
			$data->instagram = 'https://instagram.com/'.end($z);
		}
		
		return $data;
	}
	
	// Display shortcode on front-end
	public function shortcode($atts) {
		
		$a = shortcode_atts( array(
			'id' => NULL
		), $atts );
		
		if(!is_null($a['id']) && !is_admin()) {
			
			$data = $this->get_player($a['id']);
			
			$data = $this->normalize($data);
			
			return '
			<div id="athlete-card-'.$a['id'].'" class="athlete-card">
				<div>
					<div class="photo">'. (!empty($data->image) ? '<img src="'.$data->image.'" width="300px" height="300px" alt="Photo : '.$data->name.'">' : '').'</div>
					<div class="info">
						<div>
							<p class="name">'.$data->name.'</p>
							<div class="links">								
								'. (empty($data->instagram) ? '' : '<a href="'.$data->instagram.'" rel="noopener" target="_blank" class="ig">Lien vers la page Instagram de '.$data->name.'</a>' ). '								
								'. (empty($data->twitter) ? '' : '<a href="'.$data->twitter.'" rel="noopener" target="_blank" class="tw">Lien vers la page Twitter de '.$data->name.'</a>' ). '
								'. (empty($data->facebook) ? '' : '<a href="'.$data->facebook.'" rel="noopener" target="_blank" class="fb">Lien vers la page Facebook de '.$data->name.'</a>' ). '
							</div>
						</div>
						<div class="relative">
							'. (empty($data->sport) ? '' : '
							<div class="sport">
								<img src="https://www.thesportsdb.com/images/icons/'.$data->sport.'.png" width="16px" height="16px" alt="Icône : '.$data->sport.'">
								<strong>'.$data->sport.'</strong>
							</div>
							' ). '
							'. (empty($data->nationality) ? '' : '
							<div class="nation">
								<img src="https://www.thesportsdb.com/images/icons/flags/shiny/16/'.$data->nationality.'.png" width="16px" height="16px" alt="Icône : '.$data->nationality.'">
								<strong>'.$data->nationality.'</strong>
							</div>
							' ). '
							'. (empty($data->birthday) ? '' : '
							<div class="birthday">
								<img src="https://s.w.org/images/core/emoji/14.0.0/svg/1f382.svg" width="16px" height="16px" alt="Icône : Anniversaire">
								<strong>'.$data->age.' years old<span> ('.$data->birthday.')</span>
								</strong>
							</div>
							' ). '
						</div>
						'. (empty($data->team) && empty($data->height) && empty($data->weight) ? '' : '
							<div class="characteristics">
								'. (!empty($data->team) ? '<div><strong class="team">'.$data->team.'</strong></div>' : '' ). '
								'. (!empty($data->height) ? '<div><strong class="height">'.$data->height.'</strong></div>' : '' ). '
								'. (!empty($data->weight) ? '<div><strong class="weight">'.$data->weight.'</strong></div>' : '' ). '
							</div>' ). '
					</div>
				</div>
			</div>';
			
		}
		
		return false;
		
	}	
	
}

$The_Sports_DB = The_Sports_DB::get_instance();