<?php

namespace ApiVideo\Client\Api;

use Buzz\Message\MessageInterface;
use Buzz\Message\Form\FormUpload;
use Buzz\Exception\RequestException;
use ApiVideo\Client\Buzz\FormByteRangeUpload;
use ApiVideo\Client\Model\Video;
use ApiVideo\Client\Buzz\OAuthBrowser;
use Buzz\Message\RequestInterface;

class Videos
{
    /** @var int Upload chunk size in bytes */
    public $chunkSize = 64 * 1024 * 1024; // 64 MiB;

    /** @var OAuthBrowser */
    private $browser;

    /**
     * @param OAuthBrowser $browser
     */
    public function __construct(OAuthBrowser $browser)
    {
        $this->browser = $browser;
    }

    /**
     * @param string $videoId
     * @return Video
     */
    public function get($videoId)
    {
        return $this->unmarshal($this->browser->get("/videos/$videoId"));
    }

    /**
     * Incrementally iterate over a collection of elements.
     * By default the elements are returned in an array, unless you pass a
     * $callback which will be called for each instance of Video.
     * Available parameters:
     *   - currentPage (int)   current pagination page
     *   - pageSize    (int)   number of elements per page
     *   - videoIds    (array) videoIds to limit the search to
     *   - tags        (array)
     *   - metadata    (array)
     * If currentPage and pageSize are not given, the method iterates over all
     * pages of results and return an array containing all the results.
     *
     * @param array $parameters
     * @param callable $callback
     * @return Video[]|null
     */
    public function search(array $parameters = array(), $callback = null)
    {
        $params             = $parameters;
        $currentPage        = isset($parameters['currentPage']) ? $parameters['currentPage'] : 1;
        $params['pageSize'] = isset($parameters['pageSize']) ? $parameters['pageSize'] : 100;
        $allVideos          = array();

        do {
            $params['currentPage'] = $currentPage;
            $response              = $this->browser->get('/videos?'.http_build_query($parameters));
            $json                  = json_decode($response->getContent(), true);
            $videos                = $json['data'];
            if (is_null($callback)) {
                $allVideos = array_merge($allVideos, $this->castAll($videos));
            } else {
                foreach ($videos as $video) {
                    call_user_func($callback, $this->unmarshal($video));
                }
            }

            if (isset($parameters['currentPage'])) {
                break;
            }

            $pagination = $json['pagination'];
            $pagination['currentPage']++;
        } while ($pagination['pagesTotal'] > $pagination['currentPage']);

        if (is_null($callback)) {
            return $allVideos;
        }

        return null;
    }

    /**
     * @param string $title
     * @param array $properties
     * @return Video
     */
    public function create($title, $properties = array())
    {
        return $this->unmarshal(
            $this->browser->post(
                '/videos',
                array(),
                json_encode(
                    array_merge(
                        $properties,
                        array('title' => $title)
                    )
                )
            )
        );
    }

    /**
     * @param string $source Path to the file to upload
     * @param array $properties
     * @param string $videoId
     * @return Video
     * @throws \Buzz\Exception\RequestException
     * @throws \UnexpectedValueException
     */
    public function upload($source, array $properties = array(), $videoId = null)
    {
        if (!is_readable($source)) {
            throw new \UnexpectedValueException("'$source' must be a readable source file.");
        }

        if (null === $videoId) {
            if (!isset($properties['title'])) {
                $properties['title'] = basename($source);
            }

            $videoId = $this->create($properties['title'], $properties)->videoId;
        }

        $resource = fopen($source, 'rb');

        $stats  = fstat($resource);
        $length = $stats['size'];
        if (0 >= $length) {
            throw new \UnexpectedValueException("'$source' is empty.");
        }

        // Complete upload in a single request when file is small enough
        if ($this->chunkSize > $length) {
            return $this->unmarshal(
                $this->browser->submit(
                    "/videos/$videoId/source",
                    array('file' => new FormUpload($source))
                )
            );
        }

        // Split content to upload big files in multiple requests
        $i = $copiedBytes = 0;
        stream_set_chunk_size($resource, $this->chunkSize);
        $lastResponse = null;
        do {
            $chunkPath   = tempnam(sys_get_temp_dir(), 'upload-chunk-');
            $chunk       = fopen($chunkPath, 'w+b');
            $from        = $copiedBytes;
            $copiedBytes += stream_copy_to_stream($resource, $chunk, $this->chunkSize, $copiedBytes);

            try {
                $response     = $this->browser->submit(
                    "/videos/$videoId/source",
                    array('file' => new FormByteRangeUpload($chunkPath, $from, $copiedBytes, $length)),
                    RequestInterface::METHOD_POST,
                    array(
                        'Content-Range' => 'bytes '.$from.'-'.($copiedBytes - 1).'/'.$length,
                        'Expect'        => '',
                    )
                );
                $lastResponse = $this->unmarshal($response);
            } catch (RequestException $e) {
                if ($e->getCode() !== 100 && $e->getCode() >= 400) {
                    throw $e;
                }
            }

            fclose($chunk);
            unlink($chunkPath);

        } while ($copiedBytes < $length);

        fclose($resource);

        return $lastResponse;
    }

    /**
     * @param string $videoId
     * @param array $properties
     * @return Video
     */
    public function update($videoId, array $properties)
    {
        return $this->unmarshal(
            $this->browser->patch(
                "/videos/$videoId",
                array(),
                json_encode($properties)
            )
        );
    }

    /**
     * @param string $videoId
     * @return void
     */
    public function delete($videoId)
    {
        $this->browser->delete("/videos/$videoId");
    }

    /**
     * @param string $videoId
     * @return Video
     */
    public function publish($videoId)
    {
        return $this->schedule($videoId, new \DateTime);
    }

    /**
     * @param string $videoId
     * @param string|\DateTimeInterface $scheduledAt
     * @return Video
     */
    public function schedule($videoId, $scheduledAt)
    {
        $dateTime = $scheduledAt instanceof \DateTimeInterface ?
            $scheduledAt :
            new \DateTime($scheduledAt);

        return $this->unmarshal(
            $this->browser->patch(
                "/videos/$videoId",
                array(),
                json_encode(
                    array(
                        'scheduledAt' => $dateTime->format(\DateTime::ATOM),
                    )
                )
            )
        );
    }

    /**
     * @param string $videoId
     * @return Video
     */
    public function unschedule($videoId)
    {
        return $this->unmarshal(
            $this->browser->patch(
                "/videos/$videoId",
                array(),
                json_encode(
                    array(
                        'scheduledAt' => null,
                    )
                )
            )
        );
    }

    /**
     * @param \Buzz\Message\MessageInterface $message
     * @return Video
     */
    private function unmarshal(MessageInterface $message)
    {
        return $this->cast(json_decode($message->getContent(), true));
    }

    /**
     * @param array $videos
     * @return Video[]
     */
    private function castAll(array $videos)
    {
        return array_map(array($this, 'cast'), $videos);
    }

    /**
     * @param array $data
     * @return Video
     */
    private function cast(array $data)
    {
        $video              = new Video;
        $video->videoId     = $data['videoId'];
        $video->title       = $data['title'];
        $video->description = $data['description'];
        $video->tags        = $data['tags'];
        $video->metadata    = $data['metadata'];
        $video->source      = $data['source'];
        $video->assets      = $data['assets'];
        $video->publishedAt = isset($data['publishedAt']) ? \DateTimeImmutable::createFromFormat(
            \DateTime::ATOM,
            $data['publishedAt']
        ) : null;
        $video->deletedAt   = isset($data['deletedAt']) ? \DateTimeImmutable::createFromFormat(
            \DateTime::ATOM,
            $data['deletedAt']
        ) : null;

        return $video;
    }
}
