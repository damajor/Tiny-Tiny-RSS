#!/usr/bin/php4
<?php
	// this daemon runs in the background and updates all feeds
	// continuously

	define('DEFAULT_ERROR_LEVEL', E_ALL);

	declare(ticks = 1);

	define('MAGPIE_CACHE_DIR', '/var/tmp/magpie-ttrss-cache-daemon');
	define('DISABLE_SESSIONS', true);

	define('PURGE_INTERVAL', 3600); // seconds

	require_once "sanity_check.php";
	require_once "config.php";

	if (!ENABLE_UPDATE_DAEMON) {
		die("Please enable option ENABLE_UPDATE_DAEMON in config.php\n");
	}
	
	require_once "db.php";
	require_once "db-prefs.php";
	require_once "functions.php";
	require_once "magpierss/rss_fetch.inc";

	function sigint_handler() {
		unlink("update_daemon.lock");
		die("Received SIGINT. Exiting.\n");
	}

	pcntl_signal(SIGINT, sigint_handler);

	$lock_handle = make_lockfile("update_daemon.lock");

	if (!$lock_handle) {
		die("error: Can't create lockfile ($lock_filename). ".
			"Maybe another daemon is already running.\n");
	}

	$link = db_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);	

	if (!$link) {
		if (DB_TYPE == "mysql") {
			print mysql_error();
		}
		// PG seems to display its own errors just fine by default.		
		return;
	}

	if (DB_TYPE == "pgsql") {
		pg_query("set client_encoding = 'utf-8'");
		pg_set_client_encoding("UNICODE");
	}

	$last_purge = 0;

	while (true) {

		if (time() - $last_purge > PURGE_INTERVAL) {
			print "Purging old posts (random 30 feeds)...\n";
			global_purge_old_posts($link, true, 30);
			$last_purge = time();
		}

		// FIXME: get all scheduled updates w/forced refetch
		// Stub, until I figure out if it is really needed.

#		$result = db_query($link, "SELECT * FROM ttrss_scheduled_updates ORDER BY id");
#		while ($line = db_fetch_assoc($result)) {
#			print "Scheduled feed update: " . $line["feed_id"] . ", UID: " . 
#				$line["owner_uid"] . "\n";
#		}
	
		// Process all other feeds using last_updated and interval parameters

		$random_qpart = sql_random_function();

/*		
					ttrss_entries.date_entered < NOW() - INTERVAL '$purge_interval days'");
			}

			$rows = pg_affected_rows($result);
			
		} else {

			$result = db_query($link, "DELETE FROM ttrss_user_entries 
				USING ttrss_user_entries, ttrss_entries 
				WHERE ttrss_entries.id = ref_id AND 
				marked = false AND 
				feed_id = '$feed_id' AND 
				ttrss_entries.date_entered < DATE_SUB(NOW(), INTERVAL $purge_interval DAY)"); */		
		
		if (DAEMON_UPDATE_LOGIN_LIMIT > 0) {
			if (DB_TYPE == "pgsql") {
				$login_thresh_qpart = "AND ttrss_users.last_login >= NOW() - INTERVAL '".DAEMON_UPDATE_LOGIN_LIMIT." days'";
			} else {
				$login_thresh_qpart = "AND ttrss_users.last_login >= DATE_SUB(NOW(), INTERVAL ".DAEMON_UPDATE_LOGIN_LIMIT." DAY)";
			}			
		} else {
			$login_thresh_qpart = "";
		}

		$result = db_query($link, "SELECT feed_url,ttrss_feeds.id,owner_uid,
				SUBSTRING(last_updated,1,19) AS last_updated,
				update_interval 
			FROM 
				ttrss_feeds,ttrss_users 
			WHERE 
				ttrss_users.id = owner_uid $login_thresh_qpart 
			ORDER BY $random_qpart DESC");

		$user_prefs_cache = array();

		printf("Scheduled %d feeds to update...\n", db_num_rows($result));
		
		while ($line = db_fetch_assoc($result)) {
	
			$upd_intl = $line["update_interval"];
			$user_id = $line["owner_uid"];
	
			if (!$upd_intl || $upd_intl == 0) {
				if (!$user_prefs_cache[$user_id]['DEFAULT_UPDATE_INTERVAL']) {			
					$upd_intl = get_pref($link, 'DEFAULT_UPDATE_INTERVAL', $user_id);
					$user_prefs_cache[$user_id]['DEFAULT_UPDATE_INTERVAL'] = $upd_intl;
				} else {
					$upd_intl = $user_prefs_cache[$user_id]['DEFAULT_UPDATE_INTERVAL'];
				}
			}

			if ($upd_intl < 0) { 
#				print "Updates disabled.\n";
				continue; 
			}
	
			print "Feed: " . $line["feed_url"] . ": ";

			printf("(%d/%d, %d) ", time() - strtotime($line["last_updated"]),
				$upd_intl*60, $user_id);
	
			if (!$line["last_updated"] || 
				time() - strtotime($line["last_updated"]) > ($upd_intl * 60)) {
	
				print "Updating...\n";	
				update_rss_feed($link, $line["feed_url"], $line["id"], true);	
				sleep(1); // prevent flood (FIXME make this an option?)
			} else {
				print "Update not needed.\n";
			}
		}

		if (DAEMON_SENDS_DIGESTS) send_headlines_digests($link);

		print "Sleeping for " . DAEMON_SLEEP_INTERVAL . " seconds...\n";
		
		sleep(DAEMON_SLEEP_INTERVAL);
	}

	db_close($link);

?>
