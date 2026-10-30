
# APK to: play TV local, on Crome cast and on R-PI
[Buy me a coffee](https://www.paypal.com/ncp/payment/UY6RJK9AQ6HVC)
-- --
Transforms the R-PI to a 'chrome cast'. 
APK application can play TV locally (Android phone), to Google Chrome cast, or cast to a R-PI (after you install this app on R-PI) . The R_PI will play all TV codecs, while The Chrome will not

-- -- 
### Used by APK in the APK folder for alternative cast option beside ChromeCast

[this repo is private, ](https://github.com/circinusX1/fast-iptv-apk-code) utill I drink some coffee. 
Until then use precompiled APK app. Is clean.

runs this service.

```
./install.sh

```
Then on the fast-tv app  on the Android phone on the App
goto settings and discover the RPI. Then save settings.
Now you can cast TV statoins which the ChromeCast cannot decode to R-PI.
You can select AUDIO from the App settings to be over HDMI or over speakers.

-- --
![Help text](docs/fastiptv.png)


# Bonus web_server

This is the web versin of the APK simplified.

You can run it locally if you have apache2 or lighttpd with PHP
emabled, otherwise the simpler way is with just php:

```
php -S localhost:8000 # in the folder where index.php is located.
```

If you deploy it on a R-PI or an a board with HDMI and a good video card,
then you can access it by IP. Audio HDMI/Speakers play frmo the left top buttons.
Then you play it by pressing HDMI button over the HDMI.

![1000](docs/rpi-cast.png)




