# nntp-web
**Simple, stateless, web-based NNTP client**

**nntp-web** is a simple, yet powerful web-based frontend to an NNTP news server. This could be a news server carrying Usenet groups or any other news networks.

* Simple, timeless design - basic HTML, minimal CSS, no JavaScript
* Support for advanced newsgroup metadata
* Posting is not currently supported.

`nntp-web` _does not cache anything_ - all information is retrieved directly from the NNTP server for each request, and thus a low-latency, high-throughput connection to the NNTP server is advised. This behavior is intentional as it allows for realtime inspection of the behavior of NNTP servers and can be useful for debugging. The design philosophy of `nntp-web` is to rely on the server to do pretty much all the work.

## Installation and Configuration

`nntp-web` requires a reasonably modern version of PHP (8.2+).

Configuration goes in `config.php` - refer to `config.sample.php` for examples of what you can configure.

Besides that, there's not really much to it.

## Demo

A reference installation is available at https://nntpweb.phreaknet.org - basic read access is provided for certain public groups without authentication.
The news server on the backend is the `net_nntp` module that is part of the [LBBS bulletin board system package](https://github.com/InterLinked1/lbbs).
