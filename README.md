# GraciousStudios_Monthlyreports

This simple Magento 1 module sends a report of all invoices and refunds each month on the 2nd day of the month.
You can configure the email adresses to send to in the Admin section of Magento (one per line).

It's very fast since it uses raw mysql queries and takes less than a second on our production environment.
The easiest way to install it is using [modman](https://github.com/colinmollenhour/modman):

```
modman clone https://github.com/graciousstudios/GraciousStudios_Monthlyreports.git
```
