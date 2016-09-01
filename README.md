        ___ _                               _                   
       /   (_)___  ___ ___ _ __ _ __   __ _| |_ _ __ ___  _ __  
      / /\ | / __|/ __/ _ \ '__| '_ \ / _` | __| '__/ _ \| '_ \ 
     / /_/ | \__ \ (_|  __/ |  | | | | (_| | |_| | | (_) | | | |
    /_____/|_|___/\___\___|_|  |_| |_|\__,_|\__|_|  \___/|_| |_|

Small application for human grading of relevance for search results.

Installation
------------

 vagrant up

After running initializing the vagrant instance discernatron will be available
at http://192.168.33.10/

Development
-----------

When developing you may not want to setup a full OAuth pipeline with mediawiki
to be logged in. There is a provided `app.debug.php.example` file. Renaming
this to `app.debug.php` will make all connections automagically logged in.

You will additionally need some queries to score. First, while logged in,
visit http://192.168.33.10/import/query and submit a query or two. This
will set the queries as pending, to finish the import run:

   vagrant ssh -- php /vagrant/console.php import-pending

Each run of import-pending will import one query.
