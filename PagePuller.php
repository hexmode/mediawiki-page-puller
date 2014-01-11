<?php

class PagePuller {
	public $wiki;
	public $page;
	public $user;
	public $pass;
	public $useImgAuth = false;
	protected $pageList;
	protected $cookies;
	protected $context;

	public function __construct( $wiki, $page ) {
		$this->wiki = $wiki;
		$this->page = self::trimBrackets( $page );
	}

	public function setUser( $user ) {
		$this->user = $user;
	}

	public function setPass( $pass ) {
		$this->user = $pass;
	}

	public function useImgAuth( $bool ) {
		$this->useImgAuth = $bool;
	}

	static private function trimBrackets( $page ) {
		return trim( $page, "[* ]" ); /* People like to use []
									   * around wiki pages */
	}

	public function pullPage( $pageName ) {
		/* Need a better method for this.  Later. */
		$url = $this->wiki . "?title=" . urlencode($pageName) . "&action=raw";
		$this->setupContext( );
		wfSuppressWarnings( );
		$ret = file_get_contents( $url, false, $this->context );
		wfSuppressWarnings( true );
		return $ret;
	}

	public function getPageList() {
		if( $this->pageList ) {
			return $this->pageList;
		}

		$content = $this->pullPage( $this->page );
		if( $content !== false ) {
			$this->pageList = array_map( array( __CLASS__, "trimBrackets" ),
				explode( "\n", $content ) );
			$this->pageList[] = $this->page;
			return $this->pageList;
		} else {
			$err = error_get_last();
			return $err['message'];
		}
	}

	public function getPage( $page, $callback ) {
		$content = $this->pullPage( $page );

		if( $content !== false ) {
			# If it is an Image: or Media: page, fetch the file.
			$isImage = strstr( $page, "Image:" );
			$isMedia = strstr( $page, "Media:" );
			if( $isImage === $page || $isMedia === $page ) {
				/* Will only match if the name is Image:... */
				$msg = $this->pullFile( $page, $content );
			} else {
				# Put the content in the page
				try {
					$this->savePage( $page, $content );
					$msg = Status::newGood();
				} catch ( MWException $e ) {
					$msg = Status::newFatal( "pagepuller-get-error",
						wfMsg( $e->getMessage() ) );
				}
			}
		} else {
			$err = error_get_last();
			$msg = Status::newFatal( "pagepuller-get-error", $err['message'] );
		}
		call_user_func( $callback, $page, $msg );
		return true;
	}

	public function setupContext( $method = "GET", $cookies = null) {
		$opts['http']['method'] = $method;

		if( $this->cookies ) {
			$opts['http']['headers'] = "Cookie: {$this->cookies}\r\n";
		}
		$this->context = stream_context_create( $opts );
	}

	public function loginForCookies() {
		/* The password and username should really go in the body of the request */
		$url = $this->apiUrl . "?format=json&action=login&lgname={$this->user}&lgpassword={$this->pass}";

		$this->setupContext( "POST" );
		$data = file_get_contents( $url, false, $this->context );
		if($data !== false ) {
			$resp = json_decode( $data );
			if( $resp->login->result === "Success" ) {
				$pref = $resp->login->cookieprefix;
				$uid  = $resp->login->lguserid;
				$sess = $resp->login->sessionid;
				$this->cookies = "{$pref}UserId=$uid; {$pref}UserName=$username; {$pref}_session=$sess";
			}
		}
		throw new Exception( wfMessage( 'pagepull-login-failed' )->text() );
	}

	public function pullFile( $page, $contents ) {
		$repo = RepoGroup::singleton()->getLocalRepo();
		$title = Title::newFromText( $page );
		$img = new LocalFile( $title, $repo);
		$status = new Status();

		wfDebug( "pull image {$img->getRel()} from {$this->wiki}\n" );

		/* need a better way to do this, but this is MW 1.13 */
		if( $this->useImgAuth ) {
			$rep = "img_auth.php/";
		} else {
			$rep = "";
		}
		$root = str_replace( "index.php", $rep, $this->wiki );

		$this->setupContext( );
		$url = $root . $img->getRel();
		wfSuppressWarnings();
		$data = file_get_contents( $url, false, $this->context );
		wfSuppressWarnings( true );
		if($data === false ) {
			$err = error_get_last();
			wfDebug( "Error fetching file: {$err['message']}\n" );
			return Status::newFatal( "pagepuller-fetch-error", $err['message'] );
		}

		$tmpfile = tempnam( wfTempDir(), "XXX" );
		if( $tmpfile === false ) {
			$err = error_get_last();
			return Status::newFatal( "pagepuller-tmp-create-error", $err['message'] );
		}

		if( file_put_contents( $tmpfile, $data ) === false ) {
			$err = error_get_last();
			return Status::newFatal( "pagepuller-save-tmp-error", $err['message'] );
		}

		$status = $img->upload( $tmpfile, "[[Special:PullPages|PullPages]] upload", $contents );
		unlink( $tmpfile );
		if( !$status->isGood() ) {
			return Status::newFatal( "pagepuller-upload-error", $status->getWikiTextArray( ) );
		}
		return Status::newGood();
	}

	public function savePage( $page, $content ) {
		global $wgRequest, $wgUser;

		/* Skimmed from ApiEditPage -- MAH20130715 */
		if ( $page ) {
			$title = Title::newFromText( $page );
			$article = new Article($title);

			$reqArr['wpTextbox1'] = $content;
			$reqArr['wpRecreate'] = true;
			if( $article->exists() ) {
				$reqArr['wpEdittime'] = $article->getTimestamp(); /* If we don't do this for existing articles, we get a conflict */
			} else {
				$reqArr['wpEdittime'] = wfTimestampNow();
			}
			$reqArr['wpSummary'] = "[[Special:PullPages|PullPages]] import";
			$req = new FauxRequest($reqArr, true);
			$ep = new EditPage( $article );
			$ep->importFormData($req);

			# Do the actual save
				$oldRevId = $article->getRevIdFetched();
			$result = null;

			# Fake $wgRequest for some hooks inside EditPage
										   # FIXME: This interface SUCKS
										   $oldRequest = $wgRequest;
			$wgRequest = $req;
			$retval = $ep->internalAttemptSave($result);
			$wgRequest = $oldRequest;
			if ( !$retval->isGood() ) {
				throw new MWException( $retval->getHTML() );
			}
			global $wgOut;
			if( $wgOut->mRedirect !== null ) {
				/* Otherwise we end up on the last page successfully imported */
				$wgOut->mRedirect = null;
				return true;
			}
		}
		return false;
	}

}
