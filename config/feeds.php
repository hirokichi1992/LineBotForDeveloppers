<?php

// フィードのリストを配列で返す
// このファイルを修正することで、通知対象のフィードを管理できます。
return [
    [
        'name' => 'mdn',
        'url' => 'https://developer.mozilla.org/en-US/blog/rss.xml',
        'label' => 'MDN新着記事',
        'default_image_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d4/MDN_Web_Docs_logo.svg/512px-MDN_Web_Docs_logo.svg.png'
    ],
    [
        'name' => 'tech',
        'url' => 'https://yamadashy.github.io/tech-blog-rss-feed/feeds/rss.xml',
        'label' => '企業テックブログ新着記事',
        'default_image_url' => 'https://uxwing.com/wp-content/themes/uxwing/download/web-app-development/code-icon.png'
    ],
    [
        'name' => 'php',
        'url' => 'https://php.net/feed.atom',
        'label' => 'PHP公式ニュース',
        'default_image_url' => 'https://www.php.net/images/logos/php-logo.png'
    ],
    [
        'name' => 'freek_dev',
        'url' => 'https://freek.dev/feed',
        'label' => 'Freek.dev Blog',
        'default_image_url' => 'https://uxwing.com/wp-content/themes/uxwing/download/web-app-development/code-icon.png'
    ],
    [
        'name' => 'publickey',
        'url' => 'https://www.publickey1.jp/atom.xml',
        'label' => 'Publickey',
        'default_image_url' => 'https://uxwing.com/wp-content/themes/uxwing/download/web-app-development/code-icon.png'
    ],
    [
        'name' => 'aws_arch',
        'url' => 'https://aws.amazon.com/blogs/architecture/feed/',
        'label' => 'AWS Architecture Blog',
        'default_image_url' => 'https://d0.awsstatic.com/logos/powered-by-aws-white.png'
    ],
    [
        'name' => 'hacker_news',
        'url' => 'https://thehackernews.com/feeds/posts/default',
        'label' => 'The Hacker News',
        'default_image_url' => 'https://www.clipartmax.com/png/middle/419-4197780_hackernews-black-hacker-news-logo-transparent.png'
    ],
    [
        'name' => 'css_tricks',
        'url' => 'https://css-tricks.com/feed/',
        'label' => 'CSS-Tricks',
        'default_image_url' => 'https://css-tricks.com/wp-content/themes/CSS-Tricks-9/images/CSS-Tricks-logo.png'
    ],
    [
        'name' => 'qiita',
        'url' => 'https://qiita.com/popular-items/feed',
        'label' => 'Qiita トレンド',
        'default_image_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/72/Qiita_logo.svg/512px-Qiita_logo.svg.png'
    ],
    [
        'name' => 'ipa_alert',
        'url' => 'https://www.ipa.go.jp/security/rss/alert.rdf',
        'label' => 'IPA 重要なセキュリティ情報',
        'default_image_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/IPA_logo.png/938px-IPA_logo.png'
    ],
    [
        'name' => 'jvn',
        'url' => 'https://jvndb.jvn.jp/ja/rss/jvndb.rdf',
        'label' => 'JVN 新着脆弱性情報',
        'default_image_url' => 'http://jvn.jp/en/img/banner_0323.jpg'
    ],
    
    [
        'name' => 'codezine',
        'url' => 'https://codezine.jp/rss/new/20/index.xml',
        'label' => 'CodeZine',
        'default_image_url' => 'https://eczine.jp/logo/200200.png'
    ],
    [
        'name' => 'zenn_trends',
        'url' => 'https://zenn.dev/feed',
        'label' => 'Zenn トレンド記事',
        'default_image_url' => 'https://logo.svgcdn.com/s/zenn-dark.png'
    ],
    [
        'name' => 'mit_ai',
        'url' => 'https://technologyreview.com/topic/artificial-intelligence/feed/',
        'label' => 'MIT Tech Review AI',
        'default_image_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f8/Technology_Review_logo.png/2101px-Technology_Review_logo.png'
    ],
    [
        'name' => 'ai_news',
        'url' => 'https://artificialintelligence-news.com/feed/',
        'label' => 'AI News',
        'default_image_url' => 'https://brandfetch.com/ainews.co/logo-ainews.co.png'
    ],
];
