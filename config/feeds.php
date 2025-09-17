<?php

// フィードのリストを配列で返す
// このファイルを修正することで、通知対象のフィードを管理できます。
return [
    [
        'name' => 'mdn',
        'url' => 'https://developer.mozilla.org/en-US/blog/rss.xml',
        'label' => 'MDN新着記事'
    ],
    [
        'name' => 'tech',
        'url' => 'https://yamadashy.github.io/tech-blog-rss-feed/feeds/rss.xml',
        'label' => '企業テックブログ新着記事'
    ],
    [
        'name' => 'php',
        'url' => 'https://php.net/feed.atom',
        'label' => 'PHP公式ニュース'
    ],
    [
        'name' => 'freek_dev',
        'url' => 'https://freek.dev/feed',
        'label' => 'Freek.dev Blog'
    ],
    [
        'name' => 'publickey',
        'url' => 'https://www.publickey1.jp/atom.xml',
        'label' => 'Publickey'
    ],
    [
        'name' => 'aws_arch',
        'url' => 'https://aws.amazon.com/blogs/architecture/feed/',
        'label' => 'AWS Architecture Blog'
    ],
    [
        'name' => 'hacker_news',
        'url' => 'https://thehackernews.com/feeds/posts/default',
        'label' => 'The Hacker News'
    ],
    [
        'name' => 'css_tricks',
        'url' => 'https://css-tricks.com/feed/',
        'label' => 'CSS-Tricks'
    ],
    [
        'name' => 'qiita',
        'url' => 'https://qiita.com/popular-items/feed',
        'label' => 'Qiita トレンド'
    ],
    [
        'name' => 'ipa_alert',
        'url' => 'https://www.ipa.go.jp/security/rss/alert.rdf',
        'label' => 'IPA 重要なセキュリティ情報'
    ],
    [
        'name' => 'jvn',
        'url' => 'https://jvndb.jvn.jp/ja/rss/jvndb.rdf',
        'label' => 'JVN 新着脆弱性情報'
    ],
    [
        'name' => 'itmedia_career',
        'url' => 'https://rss.itmedia.co.jp/rss/2.0/itmedia_career.xml',
        'label' => 'ITmedia キャリア'
    ],
    [
        'name' => 'codezine',
        'url' => 'https://codezine.jp/rss/new/20/index.xml',
        'label' => 'CodeZine'
    ],
];
