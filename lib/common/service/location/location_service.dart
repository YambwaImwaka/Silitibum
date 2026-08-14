import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shortzz/common/manager/session_manager.dart';
import 'package:shortzz/common/service/api/user_service.dart';
import 'package:shortzz/common/widget/confirmation_dialog.dart';
import 'package:shortzz/languages/languages_keys.dart';
import 'package:shortzz/model/user_model/user_model.dart';

class LocationService {
  LocationService._();

  static final instance = LocationService._();

  Future<Position> getCurrentLocation(
      {bool isPermissionDialogShow = false}) async {
    bool serviceEnabled;
    LocationPermission permission;

    serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      if (isPermissionDialogShow) {
        await showServiceDialog();
      }
      return Future.error('Location services are still disabled.');
    }
    permission = await Geolocator.checkPermission();
    switch (permission) {
      case LocationPermission.denied:
        // Ask ONCE. This used to call requestPermission() and then, when
        // isPermissionDialogShow was set, call it a second time — so the user
        // answered the system dialog and was immediately shown it again.
        final result = await Geolocator.requestPermission();

        // Granted on the retry: carry on instead of erroring out. The old code
        // returned Future.error unconditionally, so even an "Allow" here
        // failed the call and the caller had to ask again.
        if (result == LocationPermission.whileInUse ||
            result == LocationPermission.always) {
          return await getCurrentPosition();
        }

        // Still refused. The old condition was `value != denied || value !=
        // deniedForever`, which is true for every possible value — nothing can
        // equal both — so the "please enable location" dialog fired even when
        // permission had just been granted.
        if (isPermissionDialogShow) {
          await showPermissionDialog();
        }
        return Future.error('Location permissions are denied');
      case LocationPermission.deniedForever:
        if (isPermissionDialogShow) {
          showPermissionDialog();
        }
        return Future.error('Location permissions are deniedForever');
      case LocationPermission.whileInUse:
      case LocationPermission.always:
      case LocationPermission.unableToDetermine:
        Position position = await getCurrentPosition();

        return position;
    }
  }

  Future<Position> getCurrentPosition() async {
    Position position = await Geolocator.getCurrentPosition();
    User? user = SessionManager.instance.getUser();

    final double latitude = position.latitude;
    final double longitude = position.longitude;
    final double userLatitude = user?.lat?.toDouble() ?? 0;
    final double userLongitude = user?.lon?.toDouble() ?? 0;

    // Check if position has changed significantly
    const double locationPrecision = 0.0001; // ~11 meters
    bool hasLocationChanged =
        (latitude - userLatitude).abs() > locationPrecision ||
            (longitude - userLongitude).abs() > locationPrecision;

    if (hasLocationChanged) {
      Future.wait([
        UserService.instance.updateUserDetails(lat: latitude, lon: longitude)
      ]);
    }
    return position;
  }

  Future<void> showPermissionDialog() async {
    Get.bottomSheet(ConfirmationSheet(
      title: LKey.nearbyReelsPermissionTitle.tr,
      description: LKey.nearbyReelsPermissionDescription.tr,
      onTap: () {
        openAppSettings();
      },
    ));
  }

  Future<void> showServiceDialog() async {
    await Get.bottomSheet(ConfirmationSheet(
      title: LKey.locationServicesDisabledTitle.tr,
      description: LKey.locationServicesDisabledDescription.tr,
      onTap: () async {
        await Geolocator.openLocationSettings();
      },
    ));
  }
}
