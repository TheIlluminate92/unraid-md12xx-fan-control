**WAZ Cooling Control** (formerly **MD12xx Fan Control**)

Local temperature-based fan control for Dell PowerVault MD1200 and MD1220 disk shelves. Newly added IPMI chassis control for Dell PowerEdge R730/R730xd includes its userspace dependencies; fan response, return to iDRAC automatic control, and idle curve operation are confirmed on a Dell R730xd running Unraid 7.3.3.

Also includes local IPMI chassis fan control for Dell PowerEdge R730/R730xd, disabled by default. Each shelf and the chassis may follow the Global curve or an Individual curve, always using its own temperature. Unavailable hardware sections are hidden unless requested. Existing MD12xx configuration and commissioning are preserved.
