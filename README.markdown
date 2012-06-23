AssetFix
========

AssetFix is a CLI script that rewrite your #__assets table preventing errors and slow speed of load caused by bad migrations or 
end user manual manipulation of the tables. This scripts try to offer a way to solve that problem.

Installation
------------

Get the source code from GIT:

    git clone git@github.com:fastslack/AssetFix.git

Usage
------------

$ git clone git@github.com:fastslack/AssetFix.git

$ cd AssetFix

$ cp configuration-dist.php configuration.php

$ vim configuration (edit the preferences with your database credentials)

$ php assetfix.php

