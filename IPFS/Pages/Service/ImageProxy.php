<?php

namespace IdnoPlugins\IPFS\Service {

    class ImageProxy extends \Idno\Pages\Service\Web\ImageProxy
    {

        protected function getCache()
        {

	    return false;

        }


        /**
         * Delete any cached content
         */
        public function deleteContent()
        {

            try {
		
                $url = $this->arguments[0];

                $proxyparams = "";
                $maxsize = "";
                if (!empty($this->arguments[1]))
                    $maxsize = (int)$this->arguments[1];
                if (!empty($maxsize))
                    $proxyparams .= ((strpos($proxyparams, '?')===false)? '?':'&') . "maxsize=$maxsize";

                $transform = "";
                if (!empty($this->arguments[2]))
                    $transform = strtolower($this->arguments[2]);
                if ($transform == 'none')
                    $transform = "";
                if (!empty($transform))
                    $proxyparams .= ((strpos($proxyparams, '?')===false)? '?':'&') . "transform=$transform";

                if ($url) {

                    if ($url = \Idno\Core\Webservice::base64UrlDecode($url)) {

			$file = \IdnoPlugins\IPFS\Entities\ProxiedFile::getByCacheKey(sha1("{$url}{$proxyparams}"));
			$file_meta = \IdnoPlugins\IPFS\Entities\ProxiedFile::getByCacheKey(sha1("{$url}{$proxyparams}").'_meta');
			
			$file->delete();
			$file_meta->delete();
			
                        echo json_encode([
                            'url' => $url,
                            'status' => true
                        ]);
                        exit;

                    } else {
                        throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem decoding the url"));
                    }

                } else {
                    throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("No url specified"));
                }

            } catch (\Exception $e) {

                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                $this->setResponse(500);
            }
        }

        public function getContent()
        {

            try {
		$filesystem = \Idno\Core\Idno::site()->filesystem();
		if (!($filesystem instanceof \IdnoPlugins\IPFS\IPFSFileSystem)) {
		    throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Registered filesystem is not an IPFSFileSystem"));
		}

                $url = $this->arguments[0];

                $proxyparams = "";
                $maxsize = "";
                if (!empty($this->arguments[1]))
                    $maxsize = (int)$this->arguments[1];
                if (!empty($maxsize))
                    $proxyparams .= ((strpos($proxyparams, '?')===false)? '?':'&') . "maxsize=$maxsize";

                $transform = "";
                if (!empty($this->arguments[2]))
                    $transform = strtolower($this->arguments[2]);
                if ($transform == 'none')
                    $transform = "";
                if (!empty($transform))
                    $proxyparams .= ((strpos($proxyparams, '?')===false)? '?':'&') . "transform=$transform";

                if (!empty($url)) {

                    if ($url = \Idno\Core\Webservice::base64UrlDecode($url)) {

                        $meta = \IdnoPlugins\IPFS\Entities\ProxiedFile::getByCacheKey(sha1("{$url}{$proxyparams}").'_meta');
                        if (!empty($meta)) {
                            // Found metadata

                            $now = time();
                            $stored_ts = $meta['stored_ts'];
                            $this->lastModifiedGatekeeper($stored_ts);

                            // See if this has expired
                            if ($meta['expires_ts'] >= $now) {

                                $this->setLastModifiedHeader($stored_ts);

                                if ($meta['status'] == 200) {

                                    \Idno\Core\Idno::site()->logging()->debug("Returning cached image $url");

                                    $fileid = \IdnoPlugins\IPFS\Entities\ProxiedFile::getByCacheKey(sha1("{$url}{$proxyparams}"));

				    if ($file = $filestore->findOne($fileid)) {
					if ($file instanceof \Idno\Files\CDNStorable) {
				
					    $forward_url = $file->getCDNStoredURL();
					    \Idno\Core\Idno::site()->logging()->debug("Forwarding to $forward_url");
					    
					    $this->forward($forward_url);
					    
					}
				    }
                                } else {
                                    $this->setResponse($meta['status']); // Previously there was a problem getting this image, lets return that status code and try again when the error expires
                                }

                                exit;
                            } else {
                                \Idno\Core\Idno::site()->logging()->debug("Image $url has expired");
                            }
                        }

                        // Not found, or expired
                        \Idno\Core\Idno::site()->logging()->debug("Attempting to fetch $url");

                        $meta = [
                            'maxsize' => $maxsize,
                            'transform' => $transform,
                            'filename' => basename($url)
                        ];
                        $content = "";
                        $result = \Idno\Core\Webservice::file_get_contents_ex($url);
                        if ($result !== false) {

                            \Idno\Core\Idno::site()->logging()->debug("Got something back, status code: {$result['response']}");

                            // See if we have an expiry
                            $expires_ts = time() + (60*60*24); // Default: try again in one day.
                            if (preg_match('/Expires: (.*)/', $result['header'], $matches)) {
                                if (!empty($matches[1])) {

                                    \Idno\Core\Idno::site()->logging()->debug("Found upstream Expires time of " . $matches[1]);

                                    $expires_ts = strtotime($matches[1]);
                                    if (empty($expires_ts) || ($expires_ts < time()) || ($expires_ts < time()-3600)) {  // If no expires, past expires, or really short expires then use expires of 1 day
                                        \Idno\Core\Idno::site()->logging()->debug("Invalid Expires or expires in the past");
                                        $expires_ts = time() + (60*60*24); // Error (no valid time or time in the past), reverting back to default
                                    }
                                }
                            }

                            // See if we have a content type
                            $mime = "application/octet-stream";
                            if (preg_match('/Content-Type: (.*)/', $result['header'], $matches)) {
                                if (!empty($matches[1])) {

                                    \Idno\Core\Idno::site()->logging()->debug("Found upstream Content-Type of " . $matches[1]);
                                    $mime = $matches[1];
                                }
                            }

                            $meta['stored_ts'] = time();
                            $meta['expires_ts'] = $expires_ts;
                            $meta['status'] = $result['response'];
                            $meta['mime'] = $mime;

                            if ($result['response'] == 200) {

                                \Idno\Core\Idno::site()->logging()->debug("Result should be valid content, so saving it.");

                                // Transform & scale
                                $square = false;
                                switch ($transform) {

                                    case 'square':
                                        $square = true;

                                }

                                $content = $result['content']; // Unmodified image, as a fallback

                                if ($square || (!empty($maxsize))) {

                                    if (empty($maxsize)) {
                                        $size = getimagesizefromstring($result['content']);

                                        $maxsize = ($size[0]>=$size[1] ? $size[0] : $size[1]); // Work out maxsize from image
                                    }

                                    // Scale and or transform
                                    \Idno\Core\Idno::site()->logging()->debug("Transforming image: Maxsize=$maxsize, Square=" . var_export($square, true));

                                    $tmp = \Idno\Entities\File::writeTmpFile($result['content']);
                                    if (!$tmp) throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Could not save temporary file"));

                                    if (!\Idno\Entities\File::isSVG($tmp, $tmp)) {

                                        if ($id = \Idno\Entities\File::createThumbnailFromFile($tmp, $meta['filename'], $maxsize, $square)) { // TODO: Do this more efficiently
                                            $id = explode('/', $id)[0];
                                            $file = \Idno\Entities\File::getByID($id);
                                            $content = $file->getBytes();
                                            $file->delete();
                                        } else {
                                            \Idno\Core\Idno::site()->logging()->debug("There was a problem generating a thumbnail, returning original");
                                        }

                                        unlink($tmp);
                                    } else {
                                        \Idno\Core\Idno::site()->logging()->debug("Image is SVG, transformation/resize not possible, returning original");
                                    }

                                }

                            }

                        } else {
                            // We got absolutely nothing back, lets save nothing
                            \Idno\Core\Idno::site()->logging()->debug("Got absolutely nothing back from $url, faking it.");
                            $meta['status'] = 404;
                            $meta['stored_ts'] = time();
                            $meta['expires_ts'] = time() + (60*60*24); // Try again in one day.
                        }

                        $size = strlen($content);
                        if ($size == 0) throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Looks like something went wrong, image was zero bytes big!"));
                        \Idno\Core\Idno::site()->logging()->debug("Storing " . $size . ' bytes of content.');
                        \Idno\Core\Idno::site()->logging()->debug('Meta: ' . print_r($meta, true));

			
			// Store content
			$filesystem->storeContent($content, [
			    'filename'  => "{$url}{$proxyparams}",
			    'mime_type' => 'text/plain'
			]);
			
			$filesystem->storeContent(serialize($meta), [
			    'filename'  => "{$url}{$proxyparams}".'_meta',
			    'mime_type' => 'text/plain'
			]);
			
                        \Idno\Core\Idno::site()->logging()->debug("Returning image $url");

                        $this->outputContent($content, $meta);

                        exit;

                    } else {
                        throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem decoding the url"));
                    }

                } else {
                    throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("No url specified"));
                }

            } catch (\Exception $e) {

                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                $this->setResponse(500);
            }

        }

    }

}
