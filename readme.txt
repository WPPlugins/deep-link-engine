=== Deep Link Engine ===
Tags: pingback, tag, pingbacks, tags, backlink, backlinks
Donate link: http://deeplinkengine.com
Contributors: Jared Croslow
Requires at least: 2.7
Tested up to: 3.0.0
Stable tag: 1.8.0

== Description ==

Deep link engine is a plugin that searches your post context for tags, using Yahoo or TagTheNet, those tags are used to search the blog sphere (via Google) to find relevant blogs and finally make pingback post to them. The whole process can be done automatically for multiple blog posts you have or manually on one post basis. Plugin does save the information it finds to its internal tables.

== Installation ==

1. Unpack the downladed zip archive to your local disk
2. Use FTP to transfer created folder deep-link-engine/ to your server under `...wp-content/plugins/` (you can delete locally unpacked archive after you do)
3. Login with administrative privileges to your WordPress installation
4. Switch to Plugins and find the "Deep Link Engine" in the list, click "Activate" to activate plug-in (<a href="http://codex.wordpress.com/Administration_Panels">Administration Panels</a>)
5. Default configuration is enough to get you started. You might want to sign in to get Yahoo AppID because Yahoo engine finds much better tags than the default TagTheNet engine.

== Upgrade Notice ==

= Manual Upgrade =

If you are manually upgrading, please deactivate plugin from the
[Administration Panels](http://codex.wordpress.com/Administration_Panels)
first. You can also delete previous `...wp-content/plugins/deep-link-engine` folder. Don't worry, your previous data will be kept by the upgrade process.

== Frequently asked questions ==

= How does it work? =

Here is how it works in the nutshell;
The system works by using either Yahoo or TagTheNet service to find tags in the post. After that it uses blogsearch.google engine to find either most relevant or most fresh blogs referring those tags. Then it analyzes found results and checks for valid pingback. Finally, post is updated with those links and after post has been published it pings those blogs to write two-way link. The whole thing is basically standard WP plug-in using standard WP functions and methods (no additional software installation necessary).
Plug-in adds one page to the Settings menu (Deep Link Engine). It adds two table grid panel to the Edit/Add New Post WordPress methods as well. The tags analyzer is triggered automatically on post update (save draft, save post) and pingback finder can be triggered manually (by clicking Refresh button) or you can simply publish your post and have it all done automatically.

= How can I get the Yahoo AppID? =

It is simple. Just point your browser to https://developer.apps.yahoo.com/wsregapp/ and register, it is free for non-commercial use.

= Can I use auto-blogging software and/or plugins with this plugin? =

Yes you can. The plugin has been tested with numerous auto-bloggers.

= How does the link verifier works? =

Link verfier works by checking targeted sites for a back link. If the back link has not been found it is removed from source (our) blog.
If no links remain after the check DLE removes section completely. You can also remove all links despite back link status on selected blogs by checking the
"remove links completely" option.

= How can I uninstall the Deep Link Engine completely? =

If you have never activated it, or you don't have links created by DLE, there is nothing you need to do except to manually
deactivate plug-in and delete it. If you already have links and references to pingbacks created by DLE, make sure plug-in is still
active, then go to DLE options and click on "Perform Verify". In the new window click on "Remove links completely" checkbox and
select all blogs in the list, click "Process". After processing has finished, no trace of DLE will be left on your blogs so
you can deactivate and delete plug-in.

== Changelog ==

= Version 1.8.0 (2010-06-28) =

* fixed to work with the newest WordPress (3.0)

= Version 1.7.3 (2010-05-03) =

* implemented dead links removal from the internal table

= Version 1.7.2 (2010-04-20) =

* implemented "remove links completely" option in the verifier
* updated FAQ and screenshots

= Version 1.7.1 (2010-04-16) =

* fixed problem with verify when no links are present
* fixed problem with debugging (was accidentaly left on)

= Version 1.7.0 (2010-04-15) =

* implemented link verifier and remover

= Version 1.6.3 (2010-03-29) =

* fixed problems with internet explorer

= Version 1.6.2 (2010-03-26) =

* fixed problems with empty pages when manually hunting for tags/links
* fixed problems with optin dialog

= Version 1.6.1 (2010-03-16) =

* added optional notification list entry
* corrected comments and information (old paths have been used still)
* some cosmetic changes (line endings etc.)

= Version 1.6.0 (2010-03-15) =

* first version submitted for official inclusion to the WordPress plug-ins repository
* 1.6.0 released (stable)

== Screenshots ==
[Click here to see DLE Options](http://deeplinkengine.com/screenshots/wp_1.png)

[Click here to see Mass Update screen](http://deeplinkengine.com/screenshots/wp_2.png)

[Click here to see Link Verifier screen](http://deeplinkengine.com/screenshots/wp_4.png)

[Click here to see Manual Update on post add/edit](http://deeplinkengine.com/screenshots/wp_3.png)
