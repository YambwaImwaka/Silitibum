import 'package:shortzz/common/functions/json_parse.dart';

class FilePathModel {
  FilePathModel({
    this.status,
    this.message,
    this.data,
  });

  FilePathModel.fromJson(dynamic json) {
    status = asBoolOrNull(json['status']);
    message = asStringOrNull(json['message']);
    data = asStringOrNull(json['data']);
  }

  bool? status;
  String? message;
  String? data;

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{};
    map['status'] = status;
    map['message'] = message;
    map['data'] = data;
    return map;
  }
}
