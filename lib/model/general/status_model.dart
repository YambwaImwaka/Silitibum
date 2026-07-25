import 'package:shortzz/common/functions/json_parse.dart';

class StatusModel {
  StatusModel({
    bool? status,
    String? message,
  }) {
    _status = status;
    _message = message;
  }

  StatusModel.fromJson(dynamic json) {
    _status = asBoolOrNull(json['status']);
    _message = asStringOrNull(json['message']);
  }

  bool? _status;
  String? _message;

  bool? get status => _status;

  String? get message => _message;

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{};
    map['status'] = _status;
    map['message'] = _message;
    return map;
  }
}
