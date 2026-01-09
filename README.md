**RequestToBeForgotten** is a MediaWiki extension that assists in complying with the GDPR and other privacy legislation. It offers a self-service UI for users to request anonymisation of their data in lieu of MediaWiki's inability to truly delete accounts. 

This extension assumes that a wiki farm is configured using `$wgSharedDB` or some other shared account table. It will not work with CentralAuth. 

The process once a user has requested anonymisation is automatic. A random username is generated, their account information is deleted - such as their email, real name - and their password is scrambled; this prevents any reset emails and renders the account unable to be logged into. 

Subsequently, a job is dispatched to all wikis which the user has edited, and their data is deleted from the local wikis database. 

## Licensing
This repository is free software licensed under the MIT license. This code, in its current form, is not portable to other solutions - it calls propreitary code outside of this repository to fetch the list of wikis a user has edited on. 

You are free to fork this repository and change the way it works to match your setup; but please be mindful of the license.