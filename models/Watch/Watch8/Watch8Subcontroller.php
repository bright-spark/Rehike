<?php
namespace Rehike\Model\Watch\Watch8;

use \Rehike\Model\Watch\WatchModel as WatchBase;
use \Rehike\Model\Watch\Watch7\MVideoDiscussionRenderer;
use \Rehike\Model\Watch\Watch7\MVideoDiscussionNotice;
use \Rehike\Model\Watch\Watch7\MCreatorBar;
use \Rehike\i18n;
use \Rehike\Util\PrefUtils;
use \Rehike\Signin\API as SignIn;
use \Rehike\Model\Browse\InnertubeBrowseConverter;

/**
 * Implements the watch8 subcontroller for the watch model
 * implementation.
 * 
 * @author Taniko Yamamoto <kirasicecreamm@gmail.com>
 * @author The Rehike Maintainers
 */
class Watch8Subcontroller
{
    /**
     * Called from the main watch model
     * 
     * @param object $data
     * @return object[]
     */
    public static function bakeResults(&$data, $videoId)
    {
        // Create references
        $primaryInfo = &WatchBase::$primaryInfo;
        $secondaryInfo = &WatchBase::$secondaryInfo;
        $commentSection = &WatchBase::$commentSection;

        $results = [];

        // Push creator bar if the video is yours
        if (WatchBase::$isOwner) {
            $results[] = (object) [
                "videoCreatorBarRenderer" => new MCreatorBar($videoId)
            ];
        }

        // Push primary info (if it exists)
        if (!is_null($primaryInfo)) $results[] = (object)[
            "videoPrimaryInfoRenderer" => new MVideoPrimaryInfoRenderer(WatchBase::class, $videoId)
        ];

        // Push secondary info (if it exists)
        if (!is_null($secondaryInfo)) $results[] = (object)[
            "videoSecondaryInfoRenderer" => new MVideoSecondaryInfoRenderer(WatchBase::class)
        ];

        // Push comments (if they exist)
        if (!is_null($commentSection))
        {
            $content = @$commentSection->contents[0];

            if (isset($content->continuationItemRenderer))
            {
                // If the comment section exists, create a video
                // discussion renderer that contains its continuation.

                $continuationToken = $content->continuationItemRenderer
                    ->continuationEndpoint->continuationCommand->token;
                
                // Push the continuation token to yt global
                WatchBase::$yt->commentsToken = $continuationToken;

                $results[] = (object)[
                    "videoDiscussionRenderer" => new MVideoDiscussionRenderer(
                        $continuationToken
                    )
                ];
            }
            else if (isset($content->messageRenderer))
            {
                // If the comment section renderer contains a message,
                // create a videoDiscussionNotice instead.
                $message = $content->messageRenderer->text;

                $results[] = (object)[
                    "videoDiscussionNotice" => new MVideoDiscussionNotice($message)
                ];
            }
        }

        return $results;
    }

    /**
     * Called from main watch model
     * 
     * This performs check for autoplay and moves the video
     * to its respective position if so.
     * 
     * @param object $data
     * @return object
     */
    public static function bakeSecondaryResults(&$data)
    {
        $yt = &WatchBase::$yt;
        // Get data from the reference in the datahost
        $origResults = &WatchBase::$secondaryResults;
        $response = [];
        $i18n = i18n::getNamespace("watch");

        if (isset($origResults->results))
        {
            $secondaryResults = $origResults;

            /*
             * FIX (kirasicecreamm): Detection cannot rely purely upon assumption that the renderer
             * exists based on login status. It's required to perform a more sophisticated approach
             * when an item section renderer is not used to render the recommendations.
             * 
             * Other than that, I made a silly mistake here and put this inside of the
             * autoplay condition below, which prevented it from displaying on playlists, as they
             * lack the autoplay condition.
             */
            if (isset($secondaryResults->results[1]->itemSectionRenderer->contents))
            {
                $recomsList = $secondaryResults->results[1]->itemSectionRenderer->contents;
            }
            else if (isset($secondaryResults->results))
            {
                $recomsList = $secondaryResults->results;
            }
            else
            {
                return null;
            }

            InnertubeBrowseConverter::generalLockupConverter($recomsList);

            if (self::shouldUseAutoplay($data))
            {
                if (is_countable($recomsList) && count($recomsList) > 0)
                {
                    $autoplayIndex = self::getRecomAutoplay($recomsList);

                    if (isset($_COOKIE["PREF"])) {
                        $pref = PrefUtils::parse($_COOKIE["PREF"]);
                    } else {
                        $pref = (object) [];
                    }

                    // Move autoplay video to its own object
                    $compactAutoplayRenderer = (object)[
                        "contents" => [ $recomsList[$autoplayIndex] ],
                        "infoText" => $i18n->autoplayInfoText,
                        "title" => $i18n->autoplayTitle,
                        "toggleDesc" => $i18n->autoplayToggleDesc,
                        "checked" => PrefUtils::autoplayEnabled($pref)
                    ];
                    $response += ["compactAutoplayRenderer" => $compactAutoplayRenderer];

                    // Remove the original reference to prevent it from 
                    // rendering twice
                    array_splice($recomsList, $autoplayIndex, 1);
                }
            }

            $response += ["results" => $recomsList];
            return (object)$response;
        }

        return null;
    }

    /**
     * Called from main watch model
     * 
     * This checks if the playlist is present and returns
     * the playlist data if so.
     */
    public static function bakePlaylist(): ?object
    {
        $playlist = &WatchBase::$playlist;
        $i18n = i18n::getNamespace("watch");

        // Return null if there is no playlist, this
        // makes the templater ignore it.
        $out = null;

        if (!is_null($playlist))
        {
            $list = &$playlist->playlist;
            
            $out = $list;

            // Mostly Daylin's messy work
            // TODO: cleanup
            $countText = $list->videoCountText->runs ?? null;
            
            if (!is_null($countText)) {
                $curIndex = $countText[0]->text;
                $videoCount = $countText[2]->text;

                if ("1" == $videoCount)
                {
                    $videoCount = $i18n->playlistVideosSingular;
                }
                else
                {
                    $videoCount = $i18n->playlistVideosPlural($videoCount);
                }

                $out->videoCountText = (object) [
                    "currentIndex" => $curIndex,
                    "videoCount" => $videoCount
                ];
            }

            // "previous/next video ids also need a little work
            //  let's just catch two cases with one"
            // Copied from Daylin's implementation again
            $playlistId = &WatchBase::$yt->playlistId;

            $out->isMix = substr($playlistId, 0, 2) == "RD";

            $curIndexInt = &$list->localCurrentIndex;
            $prevIndexInt = $curIndexInt - 1;
            $nextIndexInt = $curIndexInt + 1;

            if ($prevIndexInt < 0)
            {
                $prevIndexInt = count($list->contents ?? [0]) - 1;
            }

            if ($nextIndexInt > count($list->contents ?? [0]) - 1)
            {
                $nextIndexInt = 0;
            }

            $prevIndexIntPlus = $prevIndexInt + 1;
            $nextIndexIntPlus = $nextIndexInt + 1;

            $prevId = $list->contents[$prevIndexInt]
                ->playlistPanelVideoRenderer->videoId ?? null
            ;
            $prevUrl = "/watch?v={$prevId}&index={$prevIndexIntPlus}&list={$playlistId}";
            $nextId = $list->contents[$nextIndexInt]
                ->playlistPanelVideoRenderer->videoId ?? null
            ;
            $nextUrl = "/watch?v={$nextId}&index={$nextIndexIntPlus}&list={$playlistId}";

            // Push those to output
            $out->previousVideo = [
                "id" => $prevId,
                "url" => $prevUrl
            ];

            /*
             * FIX (kirasicecreamm): Taniko, you're a fucking idiot.
             * 
             * (rename the variable next time lol)
             */
            $out->nextVideo = [
                "id" => $nextId,
                "url" => $nextUrl
            ];
        }

        return $out;
    }

    /**
     * Determine autoplay use status
     * 
     * @return bool
     */
    public static function shouldUseAutoplay(&$data)
    {
        /**
         * TODO: Specific master check to disable globally,
         * useful for building watch7/etc. later.
         */

        // Disable if watch playlists available at all.
        if (is_null(WatchBase::$playlist))
        {
            return true;
        }

        // If none of the conditions above are hit, always
        // return false as a catch all
        return false;
    }

    /**
     * Get autoplay recommendation
     * 
     * @param object $results (index of the results)
     * @return int The index of the recommendation.
     */
    public static function getRecomAutoplay(&$results)
    {
        for ($i = 0; $i < count($results); $i++) if (isset($results[$i]->compactVideoRenderer))
        {
            return $i;
        }

        return 0;
    }
}