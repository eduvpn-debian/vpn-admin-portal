# Changelog

## 1.1.6 (2017-09-11)
- change session name to SID to get rid of explicit Domain binding;

## 1.1.5 (2017-09-11)
- update session handling:
  - (BUG) session cookie MUST expire at end of user agent session;
  - do not explicitly specify domain for cookie, this makes the 
    browser bind the cookie to actual domain and path;

## 1.1.4 (2017-09-10)
- update `fkooman/secookie`

## 1.1.3 (2017-08-17)
- more accurate yAxis text
- more space between graph and date labels
- use kiB/MiB/GiB/TiB in table
- make color of the bars on graph on stats page configurable

## 1.1.2 (2017-08-17)
- fix syntax error

## 1.1.1 (2017-08-17)
- font on CentOS has different path

## 1.1.0 (2017-08-17)
- add usage graphs to the "Stats" page

## 1.0.0 (2017-07-13)
- initial release
