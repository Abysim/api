## Overview

Personal hobby service that uses APIs and Laravel to communicate between various platforms.

## Social Crossposting

Messages are posted from a Telegram channel to X (Twitter), Bluesky, and Fediverse. Long messages or messages with many photos are split into several posts. Replies and quotes are handled and passed according to each platform. Automatic alt-texts are added to images when posted.

## Big Cats

Helper services for a non-commercial [Ukrainian informational project about big cats](https://bigcats.org.ua/) which data populated in social media:
- [Bluesky](https://bsky.app/profile/bigcats.org.ua)
- [X (Twitter)](https://x.com/bigcats_ua)
- [Telegram](https://t.me/bigcats_ua)
- [Facebook](https://www.facebook.com/bigcats.ua)
- [Instagram](https://www.instagram.com/bigcats_ua)

### Photos

The service posts photos of big cats from Flickr to IFTTT after manual review and approval in Telegram. The photos are ordered by author, species, and post time, preventing the posting of similar photos in a row. The frequency of posting varies by posting queue size. Only photos with the appropriate license are posted. Initially, photos are searched by tag or description, filtered out by excluded tags, and titles translated into Ukrainian.

### News
It gets Ukrainian news about big cats and posts it to the website's [news section](https://bigcats.org.ua/news) after approval in Telegram. The data is retrieved using the [NewsCatcher News API](https://www.newscatcherapi.com/).
