<?php

// doc_id và các biến "relay provider" lấy từ bundle JS của Facebook. Facebook đổi
// chúng vài tháng một lần: khi scraper báo lỗi "missing_required_variable_value"
// hoặc không còn trả về bài viết thì cần cập nhật lại 2 mục dưới đây.
return [
    'page_id' => '100063785460548',
    'page_url' => 'https://www.facebook.com/profile.php?id=100063785460548',
    'timezone' => 'Asia/Ho_Chi_Minh',
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',

    'feed_query' => [
        'name' => 'ProfileCometTimelineFeedRefetchQuery',
        'doc_id' => '28816314161325834',
    ],

    'relay_providers' => [
        'GHLShouldChangeAdIdFieldName' => false,
        'GHLShouldChangeSponsoredDataFieldName' => false,
        'CometFeedStory_enable_reactor_facepile' => false,
        'CometFeedStory_enable_social_bubbles' => false,
        'CometFeedStory_enable_post_permalink_white_space_click' => false,
        'CometUFICommentActionLinksRewriteEnabled' => false,
        'CometUFICommentAvatarStickerAnimatedImage' => false,
        'IsWorkUser' => false,
        'TestPilotShouldIncludeDemoAdUseCase' => false,
        'FBReels_deprecate_short_form_video_context_gk' => true,
        'CometUFI_dedicated_comment_routable_dialog_gk' => false,
        'FBReels_enable_view_dubbed_audio_type_gk' => false,
        'CometFeedShareMedia_shouldPrefetchShareImage' => false,
        'CometImmersivePhotoCanUserDisable3DMotion' => false,
        'WorkCometIsEmployeeGKProvider' => false,
        'IsMergQAPolls' => false,
        'FBReelsMediaFooter_comet_enable_reels_ads_gk' => false,
        'CometUFIReactionsEnableShortName' => false,
        'CometUFICommentAutoTranslationType' => 'ORIGINAL',
        'CometUFIShareActionMigration' => true,
        'CometUFISingleLineUFI' => false,
        'relay_provider_comet_ufi_ssr_seo_defer' => true,
        'FBReelsIFUTileContent_reelsIFUPlayOnHover' => false,
        'GroupsCometGYSJFeedItemHeight' => 150,
        'StoriesShouldEnablePhotosensitiveContentWarning' => false,
        'ShouldEnableBakedInTextStories' => false,
        'StoriesShouldIncludeFbNotes' => false,
    ],
];
