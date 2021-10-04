# sentry-keyman-relay

This is a super-hacky proxy for sentry events. We will only run this for a few
months while we move over to new sentry infrastructure. After that time, we'll
probably shut this down and ignore errors from old versions of Keyman, Bloom.

Basic configuration diagram:

```plain
error ==> [ sentry.keyman.com ] aka sentry-relay.keyman.com,
                    |               sentry-keyman-relay.azurewebsites.net
                    |
        +-----------+-------------------+-----------------------------+
        |                               |                             |
        |                               |                             |
        |                               |                             |
        V                               V                             V
    api/{project}/envelope/       api/{project}/*             organizations/...
        |                               |                             |
        | rewrite                       | rewrite                     | redirect
        |                               |                             |
        V                               V                             |
    proxy.php                 admin.sentry.keyman.com:3000            |
        |                               |                             |
        | fixup + rewrite               | (Sentry Relay)              |
        |                               |                             |
        V                               V                             V
    sentry.io                       sentry.io                     sentry.io
```

That is:

* /api/{project}/envelope/ needs a rewrite of DSN in the POST data, so we
  use a simple proxy.php script to do that
* all other endpoints are forwarded through Sentry Relay, which handles
  minidumps etc and does not seem to need any other data rewriting

Note that we originally looked at forwarding all traffic through proxy.php,
but PHP/fastcgi failed to handle minidump/ -- crashing the PHP process, and
this solution ended up being easier to get running.

HTTPS:

* sentry.keyman.com has a valid https certificate
* sentry-keyman-relay.azurewebsites.net has a valid https certificate
* sentry-relay.keyman.com does not have a https certificate
